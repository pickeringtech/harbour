import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const mermaidConfigPath = path.join(root, 'mermaid.config.json')
const puppeteerConfigPath = path.join(root, 'puppeteer.config.json')
const check = process.argv.includes('--check')
const marker = /<!-- harbour:diagram id="([a-z0-9-]+)" alt="([^"\r\n]+)" -->\r?\n```mermaid\r?\n([\s\S]*?)\r?\n```/g
const documents = [
    {
        template: 'README.template.md',
        output: 'README.md',
        imageDirectory: 'docs/images/readme',
        imageReference: 'docs/images/readme',
    },
    {
        template: 'docs/architecture.template.md',
        output: 'docs/architecture.md',
        imageDirectory: 'docs/images/architecture',
        imageReference: 'images/architecture',
    },
]
const mermaidConfig = await readFile(mermaidConfigPath)
const puppeteerConfig = await readFile(puppeteerConfigPath)
const packageManifest = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'))
const rendererVersion = packageManifest.devDependencies['@mermaid-js/mermaid-cli']
const renderProfile = 'transparent-background;native-svg-labels;adaptive-laravel-theme-v1'
const adaptiveTheme = `
<style id="harbour-adaptive-theme">
    #my-svg {
        color-scheme: light dark;
        background-color: transparent !important;
    }

    #my-svg .cluster > rect {
        rx: 10px;
        ry: 10px;
    }

    @media (prefers-color-scheme: dark) {
        #my-svg .cluster[id$="-without"] > rect {
            fill: #1b1b18 !important;
            stroke: #4a4a45 !important;
        }

        #my-svg .cluster[id$="-with"] > rect {
            fill: #271714 !important;
            stroke: #ff4438 !important;
        }

        #my-svg .cluster[id$="-without"] .cluster-label text {
            fill: #c7c7c1 !important;
        }

        #my-svg .cluster[id$="-with"] .cluster-label text {
            fill: #ff6b62 !important;
        }

        #my-svg .node.muted rect,
        #my-svg .node.stack rect,
        #my-svg .node.adapter rect,
        #my-svg .node.neutral rect {
            fill: #242422 !important;
            stroke: #5b5b56 !important;
        }

        #my-svg .node.muted text,
        #my-svg .node.muted tspan,
        #my-svg .node.stack text,
        #my-svg .node.stack tspan,
        #my-svg .node.adapter text,
        #my-svg .node.adapter tspan,
        #my-svg .node.neutral text,
        #my-svg .node.neutral tspan {
            fill: #d6d6d1 !important;
        }

        #my-svg .node.shared rect,
        #my-svg .node.entry rect,
        #my-svg .node.failure rect {
            fill: #30302d !important;
            stroke: #73736d !important;
        }

        #my-svg .node.shared text,
        #my-svg .node.shared tspan,
        #my-svg .node.entry text,
        #my-svg .node.entry tspan,
        #my-svg .node.failure text,
        #my-svg .node.failure tspan {
            fill: #ffffff !important;
        }

        #my-svg .node.workspace rect,
        #my-svg .node.domain rect,
        #my-svg .node.active rect {
            fill: #321815 !important;
            stroke: #ff4438 !important;
        }

        #my-svg .node.workspace text,
        #my-svg .node.workspace tspan,
        #my-svg .node.domain text,
        #my-svg .node.domain tspan,
        #my-svg .node.active text,
        #my-svg .node.active tspan {
            fill: #f5f5f3 !important;
        }

        #my-svg .node.harbour rect,
        #my-svg .node.manager rect,
        #my-svg .node.ready rect {
            fill: #f53003 !important;
            stroke: #ff6b62 !important;
        }

        #my-svg .node.harbour text,
        #my-svg .node.harbour tspan,
        #my-svg .node.manager text,
        #my-svg .node.manager tspan,
        #my-svg .node.ready text,
        #my-svg .node.ready tspan {
            fill: #ffffff !important;
        }

        #my-svg .edgeLabel rect,
        #my-svg .labelBkg {
            fill: #161615 !important;
            opacity: 0.96 !important;
        }

        #my-svg .edgeLabel text,
        #my-svg .edgeLabel tspan {
            fill: #e8e8e5 !important;
        }
    }
</style>`

const escapeMarkdownAlt = (value) => value.replaceAll('[', '\\[').replaceAll(']', '\\]')

const sameContents = async (target, expected) => {
    try {
        return (await readFile(target)).equals(expected)
    } catch (error) {
        if (error?.code === 'ENOENT') {
            return false
        }

        throw error
    }
}

const sourceFingerprint = (source) => createHash('sha256')
    .update(source)
    .update('\0')
    .update(mermaidConfig)
    .update('\0')
    .update(puppeteerConfig)
    .update('\0')
    .update(rendererVersion)
    .update('\0')
    .update(renderProfile)
    .update('\0')
    .update(adaptiveTheme)
    .digest('hex')

const applyAdaptiveTheme = (svg) => svg
    .replaceAll(/\s*!important/g, '')
    .replace('</svg>', `${adaptiveTheme}\n</svg>`)

const temporaryDirectory = await mkdtemp(path.join(tmpdir(), 'harbour-readme-'))
const executable = path.join(
    root,
    'node_modules',
    '.bin',
    process.platform === 'win32' ? 'mmdc.cmd' : 'mmdc',
)

try {
    const stale = []
    let diagramCount = 0

    for (const document of documents) {
        const templatePath = path.join(root, document.template)
        const outputPath = path.join(root, document.output)
        const diagramDirectory = path.join(root, document.imageDirectory)
        const template = await readFile(templatePath, 'utf8')
        const diagrams = [...template.matchAll(marker)]

        if (diagrams.length === 0) {
            throw new Error(`${document.template} does not contain any Harbour Mermaid diagram markers.`)
        }

        const ids = diagrams.map((match) => match[1])
        if (new Set(ids).size !== ids.length) {
            throw new Error(`${document.template} contains duplicate Harbour Mermaid diagram IDs.`)
        }

        const generatedDiagrams = new Map()
        for (const [, id, , source] of diagrams) {
            const temporaryPrefix = document.output.replaceAll(/[^a-z0-9]+/gi, '-')
            const input = path.join(temporaryDirectory, `${temporaryPrefix}-${id}.mmd`)
            const output = path.join(temporaryDirectory, `${temporaryPrefix}-${id}.svg`)

            await writeFile(input, `${source}\n`)
            execFileSync(executable, [
                '--input', input,
                '--output', output,
                '--configFile', mermaidConfigPath,
                '--puppeteerConfigFile', puppeteerConfigPath,
                '--backgroundColor', 'transparent',
                '--quiet',
            ], { cwd: root, stdio: 'inherit' })

            const fingerprint = sourceFingerprint(source)
            const stamp = `<!-- harbour:mermaid-source-sha256=${fingerprint} -->\n`
            const svg = applyAdaptiveTheme(await readFile(output, 'utf8'))
            generatedDiagrams.set(id, {
                fingerprint,
                contents: Buffer.from(stamp + svg),
            })
        }

        const notice = `<!-- Generated from ${document.template} by \`npm run readme:render\`. Do not edit directly. -->\n\n`
        const rendered = notice + template.replace(marker, (_, id, alt) => [
            `<!-- Diagram source: ${document.template}#${id} -->`,
            `![${escapeMarkdownAlt(alt)}](${document.imageReference}/${id}.svg)`,
        ].join('\n'))
        const expectedDocument = Buffer.from(rendered)

        if (check) {
            if (!await sameContents(outputPath, expectedDocument)) {
                stale.push(document.output)
            }

            for (const [id, diagram] of generatedDiagrams) {
                const relative = `${document.imageDirectory}/${id}.svg`
                let committed = ''
                try {
                    committed = await readFile(path.join(root, relative), 'utf8')
                } catch (error) {
                    if (error?.code !== 'ENOENT') {
                        throw error
                    }
                }

                const expectedStamp = `<!-- harbour:mermaid-source-sha256=${diagram.fingerprint} -->`
                if (!committed.startsWith(expectedStamp)
                    || !committed.includes('<svg')
                    || !committed.includes('<title')
                    || !committed.includes('<desc')
                    || !committed.includes('id="harbour-adaptive-theme"')
                    || !committed.includes('@media (prefers-color-scheme: dark)')) {
                    stale.push(relative)
                }
            }
        } else {
            await mkdir(diagramDirectory, { recursive: true })
            await writeFile(outputPath, expectedDocument)

            for (const [id, diagram] of generatedDiagrams) {
                await writeFile(path.join(diagramDirectory, `${id}.svg`), diagram.contents)
            }
        }

        let existing = []
        try {
            existing = (await readdir(diagramDirectory)).filter((entry) => entry.endsWith('.svg'))
        } catch (error) {
            if (error?.code !== 'ENOENT') {
                throw error
            }
        }

        for (const filename of existing) {
            if (!generatedDiagrams.has(path.basename(filename, '.svg'))) {
                const relative = `${document.imageDirectory}/${filename}`
                if (check) {
                    stale.push(relative)
                } else {
                    await rm(path.join(root, relative))
                }
            }
        }

        diagramCount += generatedDiagrams.size
    }

    if (check && stale.length > 0) {
        console.error(`Generated documentation artifacts are stale: ${stale.join(', ')}`)
        console.error('Run `npm run readme:render` and commit the results.')
        process.exitCode = 1
    } else if (check) {
        console.log(`${documents.length} generated document(s) and ${diagramCount} Mermaid SVG diagram(s) are current.`)
    } else {
        console.log(`Rendered ${documents.length} document(s) and ${diagramCount} Mermaid SVG diagram(s).`)
    }
} finally {
    await rm(temporaryDirectory, { recursive: true, force: true })
}
