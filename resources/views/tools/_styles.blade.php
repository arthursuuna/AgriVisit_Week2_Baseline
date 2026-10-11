    <style>
        :root {
            --ink: #1a202c; --muted: #5a6773; --line: #dde3ea;
            --accent: #1f6f54; --accent-soft: #f3f8f5;
            --warn: #b7791f; --warn-soft: #fffaf0;
            --danger: #c53030; --danger-soft: #fff5f5;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 2rem 1rem; background: #f7f9fb; color: var(--ink);
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .wrap { max-width: 860px; margin: 0 auto; }
        header { margin-bottom: 1.75rem; }
        h1 { margin: 0 0 .2rem; font-size: 1.6rem; color: var(--accent); }
        h2 { margin: 0 0 .75rem; font-size: 1.05rem; }
        .sub { color: var(--muted); font-size: .92rem; }
        .sub a, .nav a { color: var(--accent); }
        .nav { float: right; font-size: .9rem; margin-top: .4rem; }
        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 10px;
            padding: 1.25rem; margin-bottom: 1.25rem;
        }
        label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .35rem; }
        label.inline { display: inline; font-weight: 400; }
        select, textarea, input[type=text], input[type=date], input[type=number] {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--line);
            border-radius: 6px; font: inherit; font-size: .95rem; background: #fff;
        }
        textarea { min-height: 90px; resize: vertical; }
        .field { margin-bottom: 1rem; }
        .req { color: var(--danger); font-weight: 400; }
        .opt { color: var(--muted); font-weight: 400; }
        .help { color: var(--muted); font-size: .82rem; margin-top: .25rem; }
        .help code, .meta code, td code, .banner code {
            background: #f0f3f6; padding: .05rem .3rem; border-radius: 3px; color: var(--ink);
        }
        .row { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
        .row > .grow { flex: 1 1 220px; }
        button, .btn {
            background: var(--accent); color: #fff; border: 0; border-radius: 6px;
            padding: .65rem 1.3rem; font: inherit; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        button:hover, .btn:hover { background: #17573f; }
        button.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        button.secondary:hover { background: #f7f9fb; }
        .refusals { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .88rem; }
        .refusals a {
            border: 1px solid var(--danger); color: var(--danger); background: var(--danger-soft);
            border-radius: 20px; padding: .2rem .75rem; text-decoration: none;
        }
        .refusals a:hover { background: #fed7d7; }
        .banner { border-radius: 8px; padding: .9rem 1.1rem; margin-bottom: 1.25rem; font-size: .93rem; }
        .banner.ok      { background: var(--accent-soft); border: 1px solid var(--accent); }
        .banner.pending { background: var(--warn-soft);   border: 1px solid var(--warn); }
        .banner.refused { background: var(--danger-soft); border: 1px solid var(--danger); }
        .banner.notice  { background: #f7f9fb; border: 1px solid var(--muted); }
        .banner strong { display: block; margin-bottom: .25rem; }
        .code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 1rem; }
        pre {
            margin: .6rem 0 0; padding: .75rem .9rem; background: #fff; border: 1px solid var(--line);
            border-radius: 6px; overflow-x: auto; font-size: .85rem;
        }
        ul.errs { margin: .4rem 0 0; padding-left: 1.2rem; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th, td { text-align: left; padding: .4rem .5rem; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; }
        .tag { display: inline-block; font-size: .78rem; font-weight: 600; border-radius: 20px; padding: .05rem .55rem; }
        .tag.ok      { color: var(--accent); background: var(--accent-soft); border: 1px solid var(--accent); }
        .tag.pending { color: var(--warn);   background: var(--warn-soft);   border: 1px solid var(--warn); }
        .tag.refused { color: var(--danger); background: var(--danger-soft); border: 1px solid var(--danger); }
        .actions { display: flex; gap: .6rem; margin-top: .9rem; }
        .meta {
            font-size: .8rem; color: var(--muted); border-top: 1px solid var(--line);
            margin-top: 1.1rem; padding-top: .75rem;
        }
        .muted { color: var(--muted); }
        .approval .summary { margin: .3rem 0 .8rem; font-size: 1.12rem; font-weight: 600; }
        .approval table.preview th { width: 30%; color: var(--ink); }
        .approval table.preview td .help { margin-top: .1rem; }
        .unconfirmed { color: var(--danger); font-weight: 600; }
        /* A caution, not an error: amber like the approval panel, never red. */
        table.preview tr.warning > th, table.preview tr.warning > td {
            background: #fff3dc; border-bottom: 1px solid var(--warn);
        }
        table.preview tr.warning > th { border-left: 3px solid var(--warn); color: #7b4f12; font-weight: 700; }
        table.preview tr.warning > td { color: #5c3b0d; }
        .approval details { margin-top: .7rem; font-size: .85rem; }
        .approval .reason { margin-top: .9rem; }
        .approval .reason input { max-width: 100%; }
        .approval .expiry { margin: .7rem 0 0; font-size: .82rem; color: var(--muted); }
        .origin { font-weight: 400; color: var(--muted); }
        .tag.notice  { color: var(--muted);  background: #f7f9fb;           border: 1px solid var(--muted); }
    </style>
