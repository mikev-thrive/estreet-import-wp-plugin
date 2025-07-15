import fs from 'fs-extra';
import path from 'path';
import { fileURLToPath } from 'url';
import archiver from 'archiver';
import ignore from 'ignore';

// Get the directory name of the current module
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const pluginSlug = 'estreet-woocommerce';

const devDirectory = path.resolve(__dirname);
const distDirectory = path.resolve(__dirname, './dist');
const outputFilePath = path.join(distDirectory, `${pluginSlug}.zip`);
const zipIgnorePath = path.join(devDirectory, '.zipignore');

async function buildPlugin() {
  try {
    // Ensure the dist directory exists
    await fs.ensureDir(distDirectory);

    // Set up the default ignore patterns
    let ignorePatterns = [
      '.gitignore',
      '.zipignore',
      'build.js',
      'package.json',
      'package-lock.json',
      'composer.json',
      'composer.lock',
      'node_modules',
      '.git',
      'dist',
      'README.md'
    ];

    const ig = ignore().add(ignorePatterns);

    // Create a file to stream archive data to
    const output = fs.createWriteStream(outputFilePath);
    const archive = archiver('zip', {
      zlib: { level: 9 } // Sets the compression level
    });

    // Listen for all archive data to be written
    output.on('close', () => {
      console.log(`${archive.pointer()} total bytes`);
      console.log('Plugin has been zipped successfully.');
    });

    // Capture archive warning messages
    archive.on('warning', (err) => {
      if (err.code !== 'ENOENT') {
        throw err;
      }
    });

    // Capture archive error messages
    archive.on('error', (err) => {
      throw err;
    });

    // Pipe archive data to the file
    archive.pipe(output);

    // Append files from the dev directory, excluding those in .zipignore
    const files = await fs.readdir(devDirectory);
    for (const file of files) {
      const filePath = path.join(devDirectory, file);
      if (!ig.ignores(file)) {
        if ((await fs.stat(filePath)).isDirectory()) {
          archive.directory(filePath, file);
        } else {
          archive.file(filePath, { name: file });
        }
      }
    }

    // Wait for the archive to finalize before closing the output stream
    await archive.finalize();
  } catch (err) {
    console.error(`Error creating plugin zip: ${err.message}`);
  }
}

buildPlugin();