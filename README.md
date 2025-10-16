# AveStatics Static Site Generator

AveStatics is a lightweight PHP static site generator. It compiles Markdown content into HTML using reusable layouts and components, while automatically collecting layout assets into the final `public/` output tree.

## Project Layout

```
content/                 # Markdown content (mirrors the URL structure)
layouts/
  views/                 # Layout templates (HTML, CSS, JS)
  components/            # Reusable components
  built/                 # Generated versions of layouts & components
public/                  # Generated static site output
vendor/                  # Composer dependencies
avex.php                 # CLI entrypoint
```

Key source files:
- `src/Markdown.php`: parses Markdown and merges it into layouts.
- `src/Layout.php`: builds layouts/components and post-processes assets.
- `src/Statics.php`: iterates through content and triggers Markdown builds.
- `src/Component.php`: compiles layout components.
- `src/PathResolver.php`: resolves directories (supports `.env` overrides).

## Requirements

- PHP 8.1+
- Composer

Install dependencies:

```bash
composer install
```

## Configuration

Environment defaults are declared in `.env`:

```
AVESTATICS_DEV_HOST=127.0.0.1
AVESTATICS_DEV_PORT=8000
AVESTATICS_PUBLIC_DIR=public
AVESTATICS_CONTENT_DIR=content
AVESTATICS_LAYOUTS_DIR=layouts
AVESTATICS_BASE_URL=https://example.com
AVESTATICS_CACHE_DIR=storage/cache
```

You can change output or source directories by editing these keys.

## CLI Usage

`avex.php` is the primary entrypoint (rename or symlink to taste):

```bash
php avex.php help
php avex.php build        # Build the entire static site
php avex.php build -f     # Force rebuild (ignore timestamps)
php avex.php watch        # Start dev server & rebuild on change
```

### `build`

- Compiles every Markdown file in `content/` into `public/`.
- Generates/refreshes layouts and components under `layouts/built/`.
- Copies layout assets into `public/assets/css` and `public/assets/js`.

Use `-f` or `--force` to rebuild regardless of modification times.

### `watch`

- Launches PHP's built-in dev server on `AVESTATICS_DEV_HOST:AVESTATICS_DEV_PORT`.
- Polls `content/` and `layouts/` for changes.
- Rebuilds Markdown/layout/component outputs as soon as changes are detected.

## Content Authoring

Create Markdown files under `content/`. Each file requires a front-matter block:

```markdown
---
pagetitle: Homepage
layout: Homepage
file: index.html
---

# Welcome

Markdown body...
```

Front-matter keys:
- `layout` *(required)*: the layout folder name under `layouts/views/`.
- `file` *(optional)*: custom output filename (default is `<markdown-name>.html`).
- Any additional keys become template variables (accessible via `{{ key }}`).

## Layouts & Components

Layouts live in `layouts/views/<LayoutName>/`:
- `<LayoutName>.html`: main template.
- Optional `<LayoutName>.css` / `<LayoutName>.js`.
- Layout HTML can use `<x-md></x-md>` as the content insertion point.
- Use `{{ variable | fallback }}` to reference front-matter variables.

Components reside in `layouts/components/<ComponentName>/`. Include them with:

```html
<x-c-Button type="primary">
  <x-content-label>Click me</x-content-label>
</x-c-Button>
```

## Asset Placement

Layout files can declare assets with `data-x-location`:

```html
<script src="Homepage.js" data-x-location="footer-100"></script>
<link rel="stylesheet" href="Homepage.css" data-x-location="header-50" />
```

During build:
- Assets are copied to `public/assets/js` and `public/assets/css`.
- References are rewritten (`assets/js/...`, `assets/css/...`).
- Elements with header/footer locations are injected before `</head>` / `</body>` based on their weight.
- `location="inline"` retains the content inline.

## Development Workflow

1. Edit layouts/components under `layouts/`.
2. Add Markdown content under `content/`.
3. Run `php avex.php build` to generate the site.
4. Optionally, run `php avex.php watch` and edit files with automatic rebuilds.

Generated HTML goes into `public/`, which you can deploy to any static host.

## Notes & Tips

- Layout lookups are case-insensitive. Ensure front-matter `layout` matches the directory name.
- `-f` rebuilds both layouts/components and Markdown outputs, useful after changing layout CSS/JS.
- The watch command performs incremental rebuilds, but deleting files still triggers a full build.
- Asset scanning only processes `<script>` and `<link rel="stylesheet">` elements with `data-x-location`.
- The build currently emits Parsedown deprecation warnings on PHP 8.2+; these are harmless.

Happy static building!
