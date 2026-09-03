import { execFileSync } from 'node:child_process'
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const mermaidConfigPath = path.join(root, 'mermaid.config.json')
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
                '--quiet',
            ], { cwd: root, stdio: 'inherit' })

            generatedDiagrams.set(id, await readFile(output))
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

            for (const [id, contents] of generatedDiagrams) {
                const relative = `${document.imageDirectory}/${id}.svg`
                if (!await sameContents(path.join(root, relative), contents)) {
                    stale.push(relative)
                }
            }
        } else {
            await mkdir(diagramDirectory, { recursive: true })
            await writeFile(outputPath, expectedDocument)

            for (const [id, contents] of generatedDiagrams) {
                await writeFile(path.join(diagramDirectory, `${id}.svg`), contents)
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
