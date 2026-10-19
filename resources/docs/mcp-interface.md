# AgriVisit Guidance Corpus — MCP-style Interface Specification

*Week 6. One project capability, specified as an interface a caller outside AgriVisit could use.*

> **Generated parts.** This document is produced by `php artisan agrivisit:mcp-spec`.
>
> These sections are **read from the running system** and cannot describe an interface it does not have:
> - the tool definition and both schemas (from `SearchGuidanceTool`),
> - the similarity threshold (from config),
> - the corpus table, counts and build dates (from the `documents` and `chunks` tables),
> - the guard's categories (from config),
> - the error table (from `ToolResult`),
> - the list of tools not exposed (from the tool registry),
> - the dose screen result (run over every indexed passage when this document is generated).
>
> The rest is written by hand in `resources/docs/mcp-interface.md`.
>
> **Status: a specification, not a running server.** No MCP server is deployed. Everything below describes `search_guidance` as it runs inside AgriVisit, through the same gate an MCP server would call. Section "Permissions" lists what would have to exist before it could be offered to anyone outside.

## Capability

**Search a curated corpus of Ugandan agricultural extension manuals and get back passages relevant to a question, each with a citable reference, or nothing if no passage is relevant enough.** It is for software that helps extension workers and must ground what it says in published guidance rather than a model's general knowledge: every passage comes from a named government or sector publication, restricted content was removed before indexing, and a reference (`chunk_ref`) identifies exactly where each passage came from.

It answers "what does the published guidance say about X?". It does not answer questions, give advice, or decide anything; the caller does that, and owes the citation (see Obligations).

### The tool definition

As an MCP server would publish it in `tools/list`:

```json
{{definition}}
```

`readOnlyHint` is derived from the tool itself. `idempotentHint` holds for a fixed corpus: the same query returns the same passages until the corpus is rebuilt. `openWorldHint` is false: the tool reads only this server's own index and never the web.

## Inputs

{{input_fields}}

**Also rejected, after the schema:** a well-formed query about a restricted topic returns `GUARD_REFUSED` (see Security boundary). The query is screened before anything is retrieved.

## Outputs

`structuredContent` conforms to this schema, validated on every call before it is returned. A result that does not conform is discarded and reported as `UNEXPECTED_RESPONSE`, never returned in part.

```json
{{output_schema}}
```

- **`results` holds at most `max_results` passages, best first,** each with a similarity score of at least **{{threshold}}**.
- **`count: 0` is a success, not an error.** It means nothing in the corpus was relevant enough to return. The caller should say that it found no guidance. It should not lower its standards, retry with a looser query to get *something*, or fill the gap from a model's own knowledge. There is no fallback to the best results below the threshold: a threshold that bends under pressure is not a threshold.
- **`chunk_ref`** (e.g. `DOC-002-C031`) names the document (`DOC-002`) and the passage within it. The document part maps to the corpus table below, which gives title, publisher and licence.
- **`source_title` and `section`** identify where in the publication the passage sits; `section` is empty where the source had no heading.

### The corpus

{{corpus}}

**Built:** {{built}} The corpus is static: nothing updates it except a manual rebuild (`agrivisit:ingest`, `agrivisit:index`). A caller should treat it as current as of those dates and no later.

## Permissions

**Who may call it today: nobody outside AgriVisit, because there is no server.** Inside AgriVisit it is called by the agent, through the gate.

**How a caller is identified: not at all.** AgriVisit has no authentication. The one officer identity is configured (`OFFICER_ID`), not logged in. An MCP server built on this code as it stands would answer anyone who could reach it, and could not say who asked.

**What a caller may not do,** enforced by the gate whoever the caller is:
- reach any other tool (see What is deliberately not exposed);
- write anything;
- bypass the guard, the threshold or the output schema;
- read anything except the indexed passages: not the source files, not the embeddings, not other callers' queries.

**What would be needed before exposing it:**
1. **Caller authentication.** For an HTTP MCP server, OAuth 2.1 bearer tokens as the MCP authorization spec describes, or at minimum per-caller API keys. Every call should be attributable.
2. **Rate limiting per caller.** Every query costs an embedding call against this deployment's Google quota, and nothing limits it today.
3. **A query log per caller.** The gate already logs every call (`storage/app/tool-logs`), but with no caller field.
4. **The licence position confirmed** (see Obligations). This one blocks publishing; the others are engineering.

## Security boundary

### Guaranteed, because it is enforced in code

- **Every query is screened before retrieval.** The restricted-topic guard (categories: {{categories}}) runs inside the tool, on the query text, every time. An external caller's query would be screened exactly as the agent's is. A refused query returns `GUARD_REFUSED` and retrieves nothing.
- **No passage below the threshold is ever returned.** The threshold is {{threshold}} cosine similarity, set in Week 3 between the scores of unanswerable (0.58) and answerable (0.70–0.87) queries. There is no fallback to best-available results.
- **Restricted content was removed from the corpus at ingestion, by pattern.** Volume-based application rates, mixing ratios and named restricted sections were stripped before indexing. The counts are in the corpus table. What was removed cannot be retrieved, because it is not in the index. **This is pattern-based, not a proof.** Weight-per-area figures were deliberately kept, because in agronomy text they are almost always seed rates, yields or fertiliser rates. {{screen}} When this specification was written (2026-10-19) the screen flagged six passages, `DOC-001-C002`, `-C019`, `-C020`, `-C102`, `DOC-002-C022` and `-C031`, and every match was read: seed rates (30 kg/acre beans, 10 kg/acre maize), variety yields, and manure or fertiliser rates (1000 kg/Ha, 100 kg/Ha). None is a pesticide dose. **If the list above names any other passage, it has not been reviewed.** The screen is not applied to passages at query time, so a dose phrased in a way neither pattern recognises would pass. None is known.
- **Read-only.** The tool has no write path, and the gate would hold any write tool for officer approval anyway. Nothing a caller sends can change the corpus, the farm records or anything else.
- **Output conforms to the schema or is not returned.** Validated on every call.

### Not guaranteed

- **That the caller cites what it is given, or uses it at all.** See Obligations.
- **That the corpus is current.** It is as of the build dates above. Agronomic recommendations change: varieties, pests, approved products.
- **Anything about who the caller is.** There is no authentication.
- **That a relevant passage is found.** The corpus covers beans, maize and clonal coffee nurseries. A question about any other crop will usually, and correctly, return `count: 0`.
- **That the guard catches every restricted query.** It is a vocabulary rule with subject words. A query phrased entirely outside its vocabulary is not refused. It would still retrieve only from a corpus with restricted content removed, which is why both layers exist.

## What is deliberately not exposed, and why

The one capability worth sharing is the grounded knowledge. The judgement and the authority stay here.

{{not_exposed}}

**The agent loop is not exposed either, and this is the argument, not a formality.** A bounded loop with a human approval boundary is not a capability a caller can hold; it is a workflow the deployment owns. Its bounds (iterations, failures, runtime), its refusal rule and its approval boundary are meaningful only because AgriVisit decides them and an officer sits at the end. Exposed as a tool, it would hand out exactly the agency this project spent five weeks bounding, to a caller whose own loop, prompt and approvals AgriVisit cannot see.

## Obligations that transfer to the caller

**Cite.** Every passage carries a `chunk_ref`. The server can return it; it cannot make the caller cite it. A system that uses these passages and does not cite them has broken the grounding discipline the corpus exists to support, and **the server has no way to tell**. This is the same limit the Memory Design Note states about the agent's own citations: AgriVisit can check that a reference its own agent writes was returned to it (`agrivisit:citations`), but it cannot prove an uncited passage did not shape an answer. Across an interface even the first half is gone: the caller's output never comes back.

**Refusals are final for the topic.** A `GUARD_REFUSED` query should be reported to the caller's user, not reworded until something gets through. Inside AgriVisit the run ends on it. Outside, nothing can enforce that.

**`count: 0` means "no guidance found".** Say so. Do not fill the gap.

**Respect the sources' licences.** See the Licence column above. Two of the three publications state no licence (Government of Uganda manuals). The third is © Uganda Coffee Development Authority. AgriVisit uses them internally for extension support. Whether passages may be served to a third party is **not established**, and must be settled with the publishers before this interface is offered outside the deployment.

**Do not present passages as current advice.** They are as current as the corpus build dates.

## Errors

Returned as a tool result with `isError: true` and the code in `structuredContent.code`. Never a protocol error, except for malformed JSON-RPC itself. Every code AgriVisit has is listed, including those this capability cannot return:

{{errors}}
