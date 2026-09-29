# Corpus / Source Register

AgriVisit — AgriVisit_Capstone. Generated from the ingested corpus by `php artisan agrivisit:register`; do not edit by hand.

| Documents | Chunks | Words | Sections removed | Sentences removed | Chunks dropped |
|---|---|---|---|---|---|
| 3 | 301 | 50,662 | 2 | 32 | 5 |

## Documents

| Ref | Title | Publisher | Crops | Licence | Retrieved | Pages | Chunks | Sentences removed | Chunks dropped |
|---|---|---|---|---|---|---|---|---|---|
| DOC-001 | [Beans Training Manual for Extension Workers in Uganda](https://www.agriculture.go.ug/wp-content/uploads/2019/09/Beans-training-manual-for-extension-workers-in-Uganda.pdf) | Ministry of Agriculture, Animal Industry and Fisheries (MAAIF) | beans | Not stated — Government of Uganda publication | 2026-09-29 | 81 | 138 | 0 | 0 |
| DOC-002 | [Maize Training Manual for Extension Workers in Uganda](https://agriculture.go.ug/wp-content/uploads/2026/01/maize_manual.pdf) | Ministry of Agriculture, Animal Industry and Fisheries (MAAIF) | maize | Not stated — Government of Uganda publication | 2026-09-29 | 74 | 52 | 11 | 2 |
| DOC-003 | [Clonal Robusta Coffee Nursery Manual for Extension Workers and Nursery Operators in Uganda](https://www.agriculture.go.ug/wp-content/uploads/2020/09/UCDA-MAAIF-Clonal-Robusta-Coffee-Nursery-Manual.pdf) | Uganda Coffee Development Authority (UCDA) | coffee | © 2019 Uganda Coffee Development Authority | 2026-09-29 | 58 | 111 | 21 | 3 |

## Sections removed at ingestion

Dosing and veterinary sections are excluded before indexing, so retrieval cannot surface them. Each removal is listed here.

| Ref | Sections cut | Headings |
|---|---|---|
| DOC-001 | 2 | 4.3.2 Determining how much pesticide to use |

## Sentences removed at ingestion

Sentences and bullet items stating a mix ratio (an amount per litre or in a volume of water) are removed before chunking, so the guidance around them stays in the corpus.

| Ref | Sentences removed |
|---|---|
| DOC-002 | 11 |
| DOC-003 | 21 |

## Chunks dropped at ingestion

Chunks containing both a chemical indicator and a measured volume are dropped at ingestion, so dosing content cannot be retrieved even where it appears outside a recognised section heading.

| Ref | Chunks dropped |
|---|---|
| DOC-002 | 2 |
| DOC-003 | 3 |

## Notes

- **DOC-001** — Written for extension workers. Section 4.3.2 (pesticide dose calculation) removed at ingestion.
- **DOC-002** — Climate-smart edition. 74 pages. Chapter 7 covers agro-chemicals; section 3.6 is Fertilizer/Pesticide application.
- **DOC-003** — Nursery and propagation scope only — does not cover mature-plantation agronomy.
