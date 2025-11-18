/*
 Parse 514_Upozila_Election_Office.pdf into a CSV suitable for generate_upazilas_sql.js.

 Usage:
   node scripts/parse_pdf_upazilas.js --pdf "D:/path/to/514_Upozila_Election_Office.pdf" --out "../admin_areas_upazilas.csv"

 Notes:
 - Requires: npm install pdf-parse
 - Heuristic parser: uses known division/district names and detects Bengali text
 - Produces columns: Division, District, Upazila, UpazilaBn
 - Review the generated CSV once to spot anomalies, then run generate_upazilas_sql.js
*/

const fs = require('fs');
const path = require('path');
let pdfjsLib;
try {
  pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
} catch (e) {
  pdfjsLib = require('pdfjs-dist');
}

const args = process.argv.slice(2);
function getArg(name, def) {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : def;
}

const pdfPath = getArg('--pdf');
const outPath = getArg('--out', path.join(__dirname, '..', 'admin_areas_upazilas.csv'));

if (!pdfPath) {
  console.error('Missing --pdf "path/to/514_Upozila_Election_Office.pdf"');
  process.exit(1);
}

// Known divisions and districts (English names) for context grouping
const divisions = new Set(['Dhaka', 'Chattogram', 'Sylhet', 'Barishal', 'Khulna', 'Rajshahi', 'Rangpur', 'Mymensingh']);
const divisionByDistrict = {
  // Dhaka (1)
  Dhaka: 'Dhaka', Gazipur: 'Dhaka', Kishoreganj: 'Dhaka', Manikganj: 'Dhaka', Munshiganj: 'Dhaka', Narayanganj: 'Dhaka', Narsingdi: 'Dhaka', Tangail: 'Dhaka', Faridpur: 'Dhaka', Gopalganj: 'Dhaka', Madaripur: 'Dhaka', Rajbari: 'Dhaka', Shariatpur: 'Dhaka',
  // Chattogram (2)
  Bandarban: 'Chattogram', Brahmanbaria: 'Chattogram', Chandpur: 'Chattogram', Chattogram: 'Chattogram', "Cox's Bazar": 'Chattogram', Cumilla: 'Chattogram', Feni: 'Chattogram', Khagrachhari: 'Chattogram', Lakshmipur: 'Chattogram', Noakhali: 'Chattogram', Rangamati: 'Chattogram',
  // Rajshahi (3)
  Bogura: 'Rajshahi', Joypurhat: 'Rajshahi', Naogaon: 'Rajshahi', Natore: 'Rajshahi', 'Chapai Nawabganj': 'Rajshahi', Pabna: 'Rajshahi', Rajshahi: 'Rajshahi', Sirajganj: 'Rajshahi',
  // Khulna (4)
  Bagerhat: 'Khulna', Chuadanga: 'Khulna', Jashore: 'Khulna', Jhenaidah: 'Khulna', Khulna: 'Khulna', Kushtia: 'Khulna', Magura: 'Khulna', Meherpur: 'Khulna', Narail: 'Khulna', Satkhira: 'Khulna',
  // Barishal (5)
  Barguna: 'Barishal', Barishal: 'Barishal', Bhola: 'Barishal', Jhalokathi: 'Barishal', Patuakhali: 'Barishal', Pirojpur: 'Barishal',
  // Sylhet (6)
  Habiganj: 'Sylhet', Moulvibazar: 'Sylhet', Sylhet: 'Sylhet', Sunamganj: 'Sylhet',
  // Rangpur (7)
  Dinajpur: 'Rangpur', Gaibandha: 'Rangpur', Kurigram: 'Rangpur', Lalmonirhat: 'Rangpur', Nilphamari: 'Rangpur', Panchagarh: 'Rangpur', Rangpur: 'Rangpur', Thakurgaon: 'Rangpur',
  // Mymensingh (8)
  Jamalpur: 'Mymensingh', Mymensingh: 'Mymensingh', Netrokona: 'Mymensingh', Sherpur: 'Mymensingh',
};

// Detect Bengali script characters
function hasBengali(text) {
  return /[\u0980-\u09FF]/.test(text);
}

function clean(line) {
  return line
    .replace(/\u00A0/g, ' ') // non-breaking space
    .replace(/[•·●]/g, ' ') // bullet
    .replace(/\s+/g, ' ') // collapse spaces
    .trim();
}

function escapeCsv(val) {
  if (val == null) return '';
  const s = String(val);
  if (/[",\n]/.test(s)) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

async function main() {
  const data = await fs.promises.readFile(pdfPath);
  const loadingTask = pdfjsLib.getDocument({ data });
  const pdf = await loadingTask.promise;
  const lines = [];
  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const textContent = await page.getTextContent();
    const text = textContent.items.map((it) => it.str).join(' ');
    text.split(/\r?\n|\s{2,}/).forEach((l) => {
      const c = clean(l);
      if (c) lines.push(c);
    });
  }

  let currentDivision = null;
  let currentDistrict = null;

  const rows = []; // {division, district, upazila, upazilaBn}

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];

    // If the line exactly matches a known division, set context
    if (divisions.has(line)) {
      currentDivision = line;
      currentDistrict = null;
      continue;
    }

    // If the line matches a known district, set context; infer division if missing
    if (divisionByDistrict[line]) {
      currentDistrict = line;
      if (!currentDivision) currentDivision = divisionByDistrict[line];
      continue;
    }

    // Heuristic: lines that look like upazila names (short, capitalized, not a header)
    // Skip obvious headers or non-name lines
    if (/^(Serial|SL|Code|Office|Upazila|District|Division|Page)\b/i.test(line)) continue;
    if (/^(Total|Summary|Note)\b/i.test(line)) continue;

    // Try to split English and Bangla if present on the same line
    let upEng = null, upBn = null;
    if (hasBengali(line)) {
      // If Bengali present, attempt to split by dash or parentheses
      const parts = line.split(/[-–—\(\)]/).map(clean).filter(Boolean);
      if (parts.length >= 2) {
        // Guess: first non-Bengali token is English name, last Bengali token is Bangla
        upEng = parts.find(p => !hasBengali(p)) || null;
        upBn = parts.reverse().find(p => hasBengali(p)) || null;
      } else {
        // Entire line might be Bengali name only
        upBn = line;
      }
    } else {
      upEng = line;
    }

    // Only record when we have a district context and a plausible upazila token
    if (currentDistrict && (upEng || upBn)) {
      const row = {
        division: currentDivision || divisionByDistrict[currentDistrict] || '',
        district: currentDistrict,
        upazila: upEng || '',
        upazilaBn: upBn || '',
      };

      // Filter out junk lines: too long tokens or obvious non-place text
      const token = (row.upazila || row.upazilaBn);
      if (token && token.length <= 60 && !/\d{3,}/.test(token)) {
        rows.push(row);
      }
    }
  }

  // Post-process: dedupe consecutive duplicates
  const key = r => [r.division, r.district, r.upazila.toLowerCase(), r.upazilaBn].join('|');
  const unique = [];
  const seen = new Set();
  for (const r of rows) {
    const k = key(r);
    if (!seen.has(k)) {
      unique.push(r);
      seen.add(k);
    }
  }

  // Write CSV
  const header = 'Division,District,Upazila,UpazilaBn\n';
  const body = unique.map(r => [r.division, r.district, r.upazila, r.upazilaBn].map(escapeCsv).join(',')).join('\n');
  await fs.promises.writeFile(outPath, header + body, 'utf8');

  console.log(`Parsed ${rows.length} rows, wrote ${unique.length} unique rows to ${outPath}`);
  console.log('Next: review the CSV, then run:');
  console.log('  node scripts/generate_upazilas_sql.js --baseId 1');
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});