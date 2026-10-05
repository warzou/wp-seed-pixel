'use strict';
const path = require('node:path');
const wsl = process.env.PIXEL_QA_WSL === '1';
const command = wsl ? 'wsl.exe' : process.env.PIXEL_QA_PHP;
if (!command) throw Error('Set PIXEL_QA_PHP or PIXEL_QA_WSL for the disposable runtime');
const db = process.env.PIXEL_M3_DB;
if (db && !['pixel_m3','pixel_m3_regression','pixel_m3_final_regression'].includes(db)) throw Error('Owned disposable database required');
const args = wsl ? ['-d','Ubuntu','--',...(db ? ['env',`PIXEL_M3_DB=${db}`] : []),'bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m3-linux-php.sh'] :
    ['-d',`extension_dir=${path.dirname(command)}/ext`,'-d','extension=gd','-d','extension=exif','-d','extension=mbstring','-d','extension=pdo_sqlite','-d','extension=mysqli'];
const file = name => wsl ? `tests/${name}` : path.join(__dirname,name);
module.exports = {command,args,file,wsl};
