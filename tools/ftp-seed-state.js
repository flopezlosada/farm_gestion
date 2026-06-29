#!/usr/bin/env node
'use strict';

/**
 * Genera el fichero de estado de SamKirkland/FTP-Deploy-Action
 * (.ftp-deploy-sync-state.json) a partir del árbol local YA CONSTRUIDO.
 *
 * Por qué existe: la action, en su primer run, no encuentra estado remoto y
 * resube TODO el árbol (~21k ficheros) por FTP fichero a fichero (~0,6 s c/u
 * => ~3,5 h). Si "sembramos" el estado con los hashes del contenido que YA
 * está en el servidor (subido por los deploys lftp previos), el primer run de
 * la action ve "casi todo igual" y solo sube el delta real (segundos),
 * minimizando además la ventana de inconsistencia sobre prod en vivo.
 *
 * Fiel al formato de la lib @samkirkland/ftp-deploy:
 *   - hash = sha256 hex del contenido del fichero
 *   - name = ruta relativa al local-dir, separador '/', sin './' ni barra final
 *   - 'file' {type,name,size,hash} y 'folder' {type,name,size:undefined}
 *   - version '1.0.0'
 * Si algún borde no casara, la action resube de más (lento), NUNCA corrupción:
 * este script solo produce un JSON local, no toca el servidor. El dry-run del
 * workflow de siembra (mismo commit => debe dar 0 ficheros) valida la fidelidad.
 *
 * Uso: node tools/ftp-seed-state.js --local-dir ./ --exclude-file excludes.txt --out estado.json
 * Los excludes DEBEN coincidir con los `exclude:` del workflow de deploy.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { minimatch } = require('minimatch');

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const key = argv[i].replace(/^--/, '');
    args[key] = argv[++i];
  }
  return args;
}

const args = parseArgs(process.argv);
const localDir = args['local-dir'] || './';
const outFile = args['out'] || '.ftp-deploy-sync-state.json';
const excludeFile = args['exclude-file'];

const patterns = excludeFile
  ? fs.readFileSync(excludeFile, 'utf8').split('\n').map((l) => l.trim()).filter(Boolean)
  : [];

// Mismo criterio que readdirp+picomatch de la lib: ruta relativa con '/',
// dotfiles incluidos. Si una ruta matchea cualquier patrón, se excluye.
function isExcluded(relPath) {
  return patterns.some((p) => minimatch(relPath, p, { dot: true }));
}

function sha256(absPath) {
  return crypto.createHash('sha256').update(fs.readFileSync(absPath)).digest('hex');
}

const records = [];
const root = path.resolve(localDir);

function walk(absDir, relDir) {
  const entries = fs
    .readdirSync(absDir, { withFileTypes: true })
    .sort((a, b) => a.name.localeCompare(b.name));
  for (const entry of entries) {
    const rel = relDir ? `${relDir}/${entry.name}` : entry.name;
    if (isExcluded(rel)) continue;
    const abs = path.join(absDir, entry.name);
    if (entry.isDirectory()) {
      records.push({ type: 'folder', name: rel, size: undefined });
      walk(abs, rel);
    } else if (entry.isFile()) {
      const stat = fs.statSync(abs);
      records.push({ type: 'file', name: rel, size: stat.size, hash: sha256(abs) });
    }
  }
}

walk(root, '');

const state = {
  description:
    'DO NOT DELETE THIS FILE. This file is used to keep track of which files have been synced in the most recent deployment. If you delete this file a resync will need to be done (which can take a while) - read more: https://github.com/SamKirkland/FTP-Deploy-Action',
  version: '1.0.0',
  generatedTime: Number(args['now'] || 0),
  data: records,
};

fs.writeFileSync(outFile, JSON.stringify(state));

const files = records.filter((r) => r.type === 'file').length;
const folders = records.filter((r) => r.type === 'folder').length;
process.stderr.write(`Estado generado: ${files} ficheros, ${folders} carpetas -> ${outFile}\n`);
