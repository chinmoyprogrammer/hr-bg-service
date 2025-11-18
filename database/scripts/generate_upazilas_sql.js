/*
  Simple generator: reads admin_areas_upazilas.csv and emits seed_upazilas.sql
  Aligns with seed_admin_areas.sql upazilas column order.
  Usage:
    node hr-admin-backend/database/scripts/generate_upazilas_sql.js [--baseId 483]
  Options:
    --baseId <number>  Starting ID for upazilas. If omitted, auto-detects max id from seed_admin_areas.sql and starts from (max+1).
*/

const fs = require('fs');
const path = require('path');

// Division and district IDs must match seed_admin_areas.sql
const divisions = {
  Dhaka: 1,
  Chattogram: 2,
  Rajshahi: 3,
  Khulna: 4,
  Barishal: 5,
  Sylhet: 6,
  Rangpur: 7,
  Mymensingh: 8,
};

const districts = {
  // Dhaka division
  Dhaka: 1,
  Gazipur: 2,
  Kishoreganj: 3,
  Manikganj: 4,
  Munshiganj: 5,
  Narayanganj: 6,
  Narsingdi: 7,
  Tangail: 8,
  Faridpur: 9,
  Gopalganj: 10,
  Madaripur: 11,
  Rajbari: 12,
  Shariatpur: 13,

  // Chattogram division
  Bandarban: 14,
  Brahmanbaria: 15,
  Chandpur: 16,
  Chattogram: 17,
  "Cox's Bazar": 18,
  Cumilla: 19,
  Feni: 20,
  Khagrachhari: 21,
  Lakshmipur: 22,
  Noakhali: 23,
  Rangamati: 24,

  // Sylhet division
  Habiganj: 25,
  Moulvibazar: 26,
  Sylhet: 27,
  Sunamganj: 28,

  // Barishal division
  Barguna: 29,
  Barishal: 30,
  Bhola: 31,
  Jhalokathi: 32,
  Patuakhali: 33,
  Pirojpur: 34,

  // Khulna division
  Bagerhat: 35,
  Chuadanga: 36,
  Jashore: 37,
  Jhenaidah: 38,
  Khulna: 39,
  Kushtia: 40,
  Magura: 41,
  Meherpur: 42,
  Narail: 43,
  Satkhira: 44,

  // Rajshahi division
  Bogura: 45,
  Joypurhat: 46,
  Naogaon: 47,
  Natore: 48,
  "Chapai Nawabganj": 49,
  Pabna: 50,
  Rajshahi: 51,
  Sirajganj: 52,

  // Rangpur division
  Dinajpur: 53,
  Gaibandha: 54,
  Kurigram: 55,
  Lalmonirhat: 56,
  Nilphamari: 57,
  Panchagarh: 58,
  Rangpur: 59,
  Thakurgaon: 60,

  // Mymensingh division
  Jamalpur: 61,
  Mymensingh: 62,
  Netrokona: 63,
  Sherpur: 64,
};

function parseArgs() {
  const args = process.argv.slice(2);
  const out = { baseId: null };
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--baseId' && i + 1 < args.length) {
      out.baseId = parseInt(args[i + 1], 10) || null;
      i++;
    }
  }
  return out;
}

function csvToRows(csvPath) {
  const raw = fs.readFileSync(csvPath, 'utf8');
  const lines = raw.split(/\r?\n/).filter(Boolean);
  const header = lines.shift();
  const cols = header.split(',').map((c) => c.trim());
  // Case-insensitive mapping supporting both parser and manual CSVs
  function findCol(names) {
    for (const n of names) {
      const i = cols.findIndex((c) => c.toLowerCase() === n.toLowerCase());
      if (i >= 0) return i;
    }
    return -1;
  }
  const idx = {
    division: findCol(['Division', 'division']),
    district: findCol(['District', 'district']),
    upazila: findCol(['Upazila', 'upazila', 'Name', 'name']),
    name_bn: findCol(['UpazilaBn', 'name_bn', 'NameBn']),
    short_name: findCol(['short_name']),
  };
  return lines.map((line) => {
    const parts = line.split(',');
    const obj = {
      division: (parts[idx.division] || '').trim(),
      district: (parts[idx.district] || '').trim(),
      upazila: (parts[idx.upazila] || '').trim(),
      name_bn: (idx.name_bn >= 0 ? (parts[idx.name_bn] || '').trim() : '') || null,
      short_name: (idx.short_name >= 0 ? (parts[idx.short_name] || '').trim() : '') || null,
    };
    return obj;
  });
}

function escapeSql(str) {
  if (str == null) return 'NULL';
  return `'${String(str).replace(/'/g, "''")}'`;
}

function buildInsert(rows, baseId) {
  const values = [];
  let id = baseId;
  for (const r of rows) {
    const divisionId = divisions[r.division];
    const districtId = districts[r.district];
    if (!divisionId || !districtId || !r.upazila) {
      console.warn(`Skipping row due to missing mapping:`, r);
      continue;
    }
    values.push(
      `(${id++}, ${escapeSql(r.upazila)}, ${escapeSql(r.name_bn)}, ${escapeSql(r.short_name)}, ${districtId}, ${divisionId}, 1, NOW(), 1)`
    );
  }
  const sql = `INSERT INTO \`upazilas\` (\`id\`, \`name\`, \`name_bn\`, \`short_name\`, \`district_id\`, \`division_id\`, \`created_user_id\`, \`created_at\`, \`status\`)\nVALUES\n${values.join(',\n')};\n`;
  return sql;
}

function detectBaseId() {
  try {
    const seedPath = path.resolve(__dirname, '..', 'seed_admin_areas.sql');
    const raw = fs.readFileSync(seedPath, 'utf8');
    const lines = raw.split(/\r?\n/);
    let maxId = 0;
    for (const line of lines) {
      const m = line.match(/^\s*\((\d+),\s*'/);
      if (m) {
        const v = parseInt(m[1], 10);
        if (!Number.isNaN(v) && v > maxId) maxId = v;
      }
    }
    return maxId ? maxId + 1 : 1;
  } catch (e) {
    return 1;
  }
}

function main() {
  let { baseId } = parseArgs();
  if (!baseId) baseId = detectBaseId();
  const csvPath = path.resolve(__dirname, '..', 'admin_areas_upazilas.csv');
  const outPath = path.resolve(__dirname, '..', 'seed_upazilas.sql');
  const rows = csvToRows(csvPath);
  const sql = `-- Generated from admin_areas_upazilas.csv\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n\n${buildInsert(rows, baseId)}\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n`;
  fs.writeFileSync(outPath, sql, 'utf8');
  console.log(`Generated ${outPath} with ${rows.length} rows, starting id ${baseId}.`);
}

main();