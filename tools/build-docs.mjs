import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { marked } from 'marked'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const output = path.join(root, 'build/pages')
const base = normalizeBase(process.env.PAGES_BASE ?? '/harbour/')
const siteUrl = `https://pickeringtech.github.io${base}`
const repository = 'https://github.com/pickeringtech/harbour'
const pages = [
    { route: '', source: 'docs/site/index.md', title: 'Harbour', description: 'Lightweight isolated Laravel environments for parallel development.' },
    { route: 'getting-started', source: 'docs/site/getting-started.md', title: 'Getting started', description: 'Install Harbour and prepare isolated Laravel workspaces.' },
    { route: 'commands', source: 'docs/site/commands.md', title: 'Commands', description: 'Understand every Harbour workspace command and when to run it.' },
    { route: 'configuration', source: 'docs/site/configuration.md', title: 'Configuration', description: 'Configure identity, ports, variables, databases, and hooks.' },
    { route: 'isolation', source: 'docs/site/isolation.md', title: 'Isolation', description: 'How Harbour isolates databases, Redis, queues, sessions, Vite, and Reverb.' },
    { route: 'integrations', source: 'docs/site/integrations.md', title: 'Integrations', description: 'Use Harbour with Git worktrees, Orca, Herdr, Sail, Docker, and Compose.' },
    { route: 'safety', source: 'docs/site/safety.md', title: 'Safety', description: 'Harbour resource ownership, environment preservation, and production safeguards.' },
    { route: 'architecture', source: 'docs/site/architecture.md', title: 'Architecture', description: 'Harbour lifecycle, domain boundaries, and architectural decisions.' },
]

marked.use({
    gfm: true,
    breaks: false,
})

await rm(output, { recursive: true, force: true })
await mkdir(output, { recursive: true })
await cp(path.join(root, 'docs/images'), path.join(output, 'images'), { recursive: true })

for (const page of pages) {
    const markdown = await readFile(path.join(root, page.source), 'utf8')
    const content = prefixRootLinks(await marked.parse(markdown))
    const directory = page.route === '' ? output : path.join(output, page.route)
    await mkdir(directory, { recursive: true })
    await writeFile(path.join(directory, 'index.html'), layout(page, content))
}

await writeFile(path.join(output, '404.html'), layout(
    { route: '404', title: 'Page not found', description: 'The requested Harbour documentation page does not exist.' },
    `<h1>Page not found</h1><p>Return to the <a href="${base}">Harbour documentation</a>.</p>`,
))
await writeFile(path.join(output, '.nojekyll'), '')
await writeFile(path.join(output, 'sitemap.xml'), sitemap())

console.log(`Built ${pages.length} documentation pages in ${path.relative(root, output)}.`)

function layout(page, content) {
    const canonical = `${siteUrl}${page.route === '' ? '' : `${page.route}/`}`
    const nav = pages.slice(1).map(item => `<a${item.route === page.route ? ' aria-current="page"' : ''} href="${base}${item.route}/">${escapeHtml(item.title)}</a>`).join('')
    const title = page.route === '' ? 'Harbour — isolated Laravel workspaces' : `${page.title} — Harbour`

    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="${escapeHtml(page.description)}">
    <meta name="theme-color" content="#f53003">
    <meta property="og:type" content="website">
    <meta property="og:title" content="${escapeHtml(title)}">
    <meta property="og:description" content="${escapeHtml(page.description)}">
    <meta property="og:url" content="${canonical}">
    <link rel="canonical" href="${canonical}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%23f53003%22/><path d=%22M24 22v56M76 22v56M24 50h52%22 stroke=%22white%22 stroke-width=%2212%22 stroke-linecap=%22round%22/></svg>">
    <title>${escapeHtml(title)}</title>
    <style>${stylesheet()}</style>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="${base}" aria-label="Harbour home"><span>H</span>Harbour</a>
        <nav aria-label="Documentation">${nav}<a href="${repository}">GitHub ↗</a></nav>
    </header>
    <div class="layout">
        <aside aria-label="Documentation sections">${nav}</aside>
        <main>${content}</main>
    </div>
    <footer><span>Harbour is open-source software released under the MIT License.</span><a href="${repository}">View on GitHub</a></footer>
</body>
</html>
`
}

function normalizeBase(value) {
    const normalized = value.replace(/^\/+|\/+$/g, '')

    return normalized === '' ? '/' : `/${normalized}/`
}

function prefixRootLinks(html) {
    return html
        .replaceAll(/href="\/(?!\/)/g, `href="${base}`)
        .replaceAll(/src="\/(?!\/)/g, `src="${base}`)
}

function sitemap() {
    const urls = pages.map(page => `  <url><loc>${siteUrl}${page.route === '' ? '' : `${page.route}/`}</loc></url>`).join('\n')

    return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`
}

function escapeHtml(value) {
    return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;')
}

function stylesheet() {
    return `
:root{color-scheme:light dark;--red:#f53003;--red-dark:#d62a00;--ink:#1b1b18;--muted:#706f6c;--paper:#fff;--soft:#fff7f5;--line:#e3e3e0;--code:#f7f7f5;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-synthesis:none}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);line-height:1.65}.site-header{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:2rem;padding:.8rem max(1.25rem,calc((100vw - 1180px)/2));border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--paper) 92%,transparent);backdrop-filter:blur(14px)}.brand{display:flex;align-items:center;gap:.65rem;color:var(--ink);font-size:1.08rem;font-weight:750;text-decoration:none}.brand span{display:grid;width:2rem;height:2rem;place-items:center;border-radius:.5rem;background:var(--red);color:white}.site-header nav{display:flex;gap:1.05rem;overflow:auto;white-space:nowrap}.site-header nav a,aside a{color:var(--muted);font-size:.9rem;text-decoration:none}.site-header nav a:hover,.site-header nav a[aria-current=page],aside a:hover,aside a[aria-current=page]{color:var(--red)}.layout{display:grid;grid-template-columns:190px minmax(0,780px);gap:4rem;max-width:1180px;margin:0 auto;padding:3.5rem 1.25rem 6rem}aside{position:sticky;top:5.5rem;align-self:start;display:grid;gap:.65rem}main{min-width:0}h1{margin:0 0 1.15rem;font-size:clamp(2.25rem,7vw,4.5rem);line-height:1.02;letter-spacing:-.055em}h2{margin:3.2rem 0 1rem;padding-top:.4rem;font-size:1.7rem;line-height:1.25;letter-spacing:-.025em}h3{margin:2rem 0 .65rem;font-size:1.15rem}p,li{color:var(--muted)}strong{color:var(--ink)}a{color:var(--red);text-underline-offset:.18em}blockquote{margin:1.5rem 0;padding:.8rem 1.15rem;border-left:3px solid var(--red);background:var(--soft);border-radius:0 .6rem .6rem 0}blockquote p{margin:0;color:var(--ink)}pre{overflow:auto;padding:1rem 1.15rem;border:1px solid var(--line);border-radius:.7rem;background:var(--code);font-size:.9rem;line-height:1.55}code{font-family:"SFMono-Regular",Consolas,"Liberation Mono",monospace;color:var(--ink)}:not(pre)>code{padding:.15em .35em;border-radius:.3rem;background:var(--code);font-size:.88em}table{display:block;overflow:auto;width:100%;border-collapse:collapse}th,td{padding:.65rem .8rem;border:1px solid var(--line);text-align:left}th{background:var(--soft);color:var(--ink)}img{display:block;max-width:100%;height:auto;margin:2rem auto}.hero{padding:2rem 0 1rem}.hero .eyebrow{margin-bottom:1rem;color:var(--red);font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.hero .lead{max-width:680px;font-size:1.25rem;color:var(--ink)}.actions{display:flex;flex-wrap:wrap;gap:.75rem;margin:1.75rem 0}.button{display:inline-flex;padding:.62rem 1rem;border:1px solid var(--red);border-radius:.55rem;background:var(--red);color:white;font-weight:700;text-decoration:none}.button.secondary{background:transparent;color:var(--red)}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin:2rem 0}.card{padding:1rem 1.1rem;border:1px solid var(--line);border-radius:.8rem;background:var(--soft)}.card h3{margin:.1rem 0}.card p{margin:.4rem 0;font-size:.92rem}footer{display:flex;justify-content:space-between;gap:2rem;max-width:1180px;margin:0 auto;padding:2rem 1.25rem;border-top:1px solid var(--line);color:var(--muted);font-size:.85rem}
@media(max-width:900px){.site-header nav{display:none}.layout{grid-template-columns:1fr;padding-top:2.5rem}aside{position:static;display:flex;overflow:auto;padding-bottom:1rem;border-bottom:1px solid var(--line);white-space:nowrap}.cards{grid-template-columns:1fr}footer{flex-direction:column}}
@media(prefers-color-scheme:dark){:root{--ink:#f5f5f3;--muted:#c7c7c1;--paper:#11110f;--soft:#271714;--line:#44443f;--code:#20201e}.site-header{background:color-mix(in srgb,var(--paper) 90%,transparent)}}
`
}
