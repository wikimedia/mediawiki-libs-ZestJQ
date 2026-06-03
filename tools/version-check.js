#!/usr/bin/env node
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));

const packageJson = JSON.parse(readFileSync(__dirname + '/../package.json', 'utf8'));
const packageLockJson = JSON.parse(readFileSync(__dirname + '/../package-lock.json', 'utf8'));

// Parse version from HISTORY.md: if first ## line is "x.x.x (not yet released)",
// the expected version is <next ## version>-git; otherwise it's an exact match.
const history = readFileSync(__dirname + '/../HISTORY.md', 'utf8');
let historyVersion = null;
let historyIsPrerelease = false;
for (const line of history.split('\n')) {
	const match = line.match(/^## (\S+)/);
	if (match) {
		if (match[1] === 'x.x.x') {
			historyIsPrerelease = true;
		} else {
			historyVersion = match[1];
			break;
		}
	}
}
if (historyIsPrerelease && historyVersion) {
	historyVersion += '-git';
}

let errors = 0;
let advice = null;
if (packageJson.version !== packageLockJson.version) {
	advice = "Run `npm install --package-lock-only`";
	errors++;
}
if (packageJson.version !== packageLockJson.packages[""].version) {
	advice = "Run `npm install --package-lock-only`";
	errors++;
}
if (packageJson.version !== historyVersion) {
	advice = advice || 'Perhaps you need to run `composer update-history`';
	errors++;
}

if (errors) {
	console.log("*** ZestJQ version mismatch! ***");
	console.log("package.json     ", packageJson.version);
	console.log("package-lock.json", packageLockJson.version);
	console.log("                 ", packageLockJson.packages[""].version);
	console.log("HISTORY.md       ", historyVersion || '<unknown>');
	if (advice) {
		console.log("");
		console.log(advice);
	}
	process.exit(1);
}
console.log("Version check ok.");
process.exit(0);
