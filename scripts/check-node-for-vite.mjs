const [major, minor] = process.version
    .slice(1)
    .split('.')
    .map(Number);
const ok =
    (major === 20 && minor >= 19) ||
    major === 21 ||
    (major === 22 && minor >= 12) ||
    major > 22;
if (!ok) {
    console.error(
        `Vite 8 needs Node 20.19+ or 22.12+ (current: ${process.version}).\n` +
            '  nvm:  nvm install && nvm use\n' +
            '  apt:  use /usr/bin/node if this server has Node 22 from NodeSource.',
    );
    process.exit(1);
}
