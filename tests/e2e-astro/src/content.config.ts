import { defineCollection } from 'astro:content'
import { contentrainLoader } from '@contentrain/query/astro'
import { readdirSync } from 'node:fs'
import { join } from 'node:path'

// Every model Bridge delivered becomes a collection; nothing is hand-picked.
const root = process.env.CONTENTRAIN_STORE as string
const models = readdirSync(join(root, '.contentrain/models')).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5))

export const collections = Object.fromEntries(
  models.map((model) => [model, defineCollection({ loader: contentrainLoader({ model, root }) })]),
)
