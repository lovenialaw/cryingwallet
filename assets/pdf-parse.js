function latin1(u8) {
    let s = '';
    const chunk = 0x8000;
    for (let i = 0; i < u8.length; i += chunk) {
        s += String.fromCharCode.apply(null, u8.subarray(i, i + chunk));
    }
    return s;
}

function inflatePdfStream(bytes) {
    const tries = [];
    try { tries.push(pako.inflate(bytes)); } catch (e) { /* skip */ }
    try { tries.push(pako.inflateRaw(bytes)); } catch (e) { /* skip */ }
    if (bytes.length > 2) {
        try { tries.push(pako.inflateRaw(bytes.subarray(2))); } catch (e) { /* skip */ }
    }
    for (const t of tries) {
        if (t && t.length) return latin1(t);
    }
    return latin1(bytes);
}

function readPdfLiteral(content, start) {
    let out = '';
    for (let i = start + 1; i < content.length; i++) {
        const ch = content[i];
        if (ch === '\\' && i + 1 < content.length) {
            const next = content[i + 1];
            const map = { n: '\n', r: '\r', t: '\t', '(': '(', ')': ')', '\\': '\\' };
            out += map[next] !== undefined ? map[next] : next;
            i++;
            continue;
        }
        if (ch === ')') return [out, i];
        out += ch;
    }
    return [out, content.length - 1];
}

function pdfTjText(body) {
    let out = '';
    for (let i = 0; i < body.length; i++) {
        if (body[i] === '(') {
            const got = readPdfLiteral(body, i);
            out += got[0];
            i = got[1];
        } else if (body[i] === '<' && body[i + 1] !== '<') {
            const end = body.indexOf('>', i);
            if (end < 0) break;
            let hex = body.slice(i + 1, end).replace(/\s+/g, '');
            if (hex.length % 2 === 1) hex += '0';
            for (let h = 0; h < hex.length; h += 2) {
                out += String.fromCharCode(parseInt(hex.slice(h, h + 2), 16) || 0);
            }
            i = end;
        }
    }
    return out;
}

function pdfOperatorsToText(content) {
    let x = 0, y = 0, leading = 12, lastY = null, lastX = 0, out = '';
    const emit = (text) => {
        if (!text) return;
        if (lastY !== null && Math.abs(y - lastY) > 3) out += '\n';
        else if (x > lastX + 4) out += ' ';
        out += text;
        lastY = y;
        lastX = x + text.length * 3.2;
    };
    let i = 0;
    while (i < content.length) {
        const ch = content[i];
        if (ch === '(') {
            const got = readPdfLiteral(content, i);
            const rest = content.slice(got[1] + 1, got[1] + 21);
            if (/^\s*'/.test(rest)) {
                y -= leading;
                emit(got[0]);
                i = got[1] + rest.indexOf("'") + 1;
                continue;
            }
            emit(got[0]);
            i = got[1] + 1;
            continue;
        }
        if (ch === '[') {
            const m = content.slice(i).match(/^\[(.*?)\]\s*TJ/s);
            if (m) {
                emit(pdfTjText(m[1]));
                i += m[0].length;
                continue;
            }
        }
        let m = content.slice(i, i + 40).match(/^(-?[\d.]+)\s+(-?[\d.]+)\s+Td/);
        if (m) { x += +m[1]; y += +m[2]; i += m[0].length; continue; }
        m = content.slice(i, i + 40).match(/^(-?[\d.]+)\s+(-?[\d.]+)\s+TD/);
        if (m) { leading = -(+m[2]); x += +m[1]; y += +m[2]; i += m[0].length; continue; }
        m = content.slice(i, i + 120).match(/^(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Tm/);
        if (m) { x = +m[5]; y = +m[6]; i += m[0].length; continue; }
        m = content.slice(i, i + 24).match(/^(-?[\d.]+)\s+TL/);
        if (m) { leading = +m[1]; i += m[0].length; continue; }
        if (ch === 'T' && content[i + 1] === '*') { y -= leading; i += 2; continue; }
        i++;
    }
    return out.replace(/[^\P{C}\n]+/gu, ' ').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

function extractPdfText(buffer) {
    const u8 = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    const raw = latin1(u8);
    if (!raw.startsWith('%PDF')) throw new Error('That file is not a readable PDF.');
    if (raw.includes('/Encrypt')) {
        throw new Error('This statement is password-protected. Open it, print/save as a new PDF without a password, then upload again.');
    }
    const chunks = [];
    const re = /stream\r?\n([\s\S]*?)endstream/g;
    let m;
    while ((m = re.exec(raw))) {
        let inner = m[1];
        if (inner.startsWith('\n') || inner.startsWith('\r')) inner = inner.replace(/^\r?\n/, '');
        inner = inner.replace(/\r?\n$/, '');
        const bytes = new Uint8Array(inner.length);
        for (let i = 0; i < inner.length; i++) bytes[i] = inner.charCodeAt(i) & 255;
        const decoded = inflatePdfStream(bytes);
        if (!decoded || (decoded.split('\0').length - 1) > decoded.length * 0.05) continue;
        if (!/Tj|TJ|Td|Tm|\(/.test(decoded)) continue;
        const text = pdfOperatorsToText(decoded);
        if (text.trim().length > 8) chunks.push(text);
    }
    return chunks.join('\n\n').trim();
}

function parseDate(raw) {
    raw = String(raw || '').trim();
    let m = raw.match(/^(\d{1,2})\/(\d{2})\/(\d{2,4})$/);
    if (!m) m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m && m[0].includes('-')) return m[0];
    if (!m) return '';
    let y = +m[3];
    if (y < 100) y += 2000;
    const month = m[2].padStart(2, '0');
    const day = m[1].padStart(2, '0');
    return y + '-' + month + '-' + day;
}

function islamIsAmountLine(line) {
    return /^[\d,]*\.\d{2}-?$/.test(String(line).trim());
}
function islamNum(raw) {
    return Math.abs(parseFloat(String(raw).replace(/[, ]/g, '').replace(/-$/, '')) || 0);
}
function islamRef(line) {
    return /^(?=.*\d)[A-Z0-9]{8,}$/.test(line);
}
function islamAcquirerLine(line) {
    return /^(MBB\b|MAYBANK|PUBLIC BANK BERHAD|RHB BANK BERHAD|CIMB |HONG LEONG|HLBB)/i.test(line);
}
function islamDetailLabel(line) {
    return /^(weekly allowance|seb allowance|fund transfer|exam fee|exam refund|math camp|extra|subscription|mio\s?math|rt reimbursement|application cambridge|tara,?\s*tmua)$/i.test(line);
}
function islamPersonLine(line) {
    if (/GRAB|MART|BANK|QR Payment|BERHAD|SDN|BHD|STORE|FOOD|RAIL|HOSTINGER|PETALIN|SELANGO/i.test(line)) return false;
    return /^(VENNY SOETANTO|LOVENIA LAW CIA SHIN|[A-Z][A-Z'.]+(?: [A-Z][A-Z'.]+){1,4})$/i.test(line);
}
function islamIsFamilyDetail(group) {
    return /weekly allowance|seb allowance/i.test(group);
}
function islamIsFooterLine(line) {
    return /^(NO |LOT |JALAN |JLN |KUCHING|MYR|OLcustom)/i.test(line) || /^\d{1,2}\/\d{1,2}\/\d{4}\s*[-–]/.test(line);
}
function islamTxnType(line) {
    if (islamRef(line) || /^(TOTAL|ENDING BALANCE|BEGINNING BALANCE)$/i.test(line)) return false;
    if (/^(B\/F|DRC DEBIT|DRC CREDIT|DUITNOW TRANSFER|DUITNOW QR|MYDEBIT PURCHASE(?:\s+-\s+S)?|CASH WITHDRAWAL|ATM WITHDRAWAL|IBG TRANSFER|FUND TRANSFER|CR TFR\/SAL\/MISC|SERVICE CHARGE\/MISC|INWARD IBG|INWARD CREDIT|INWARD FUND|HIBAH PAID|FPX DEBIT|FPX CREDIT|CASH DEPOSIT|CHEQUE DEPOSIT|CARDLESS WITHDRAWAL|GIRO|INSTANT TRANSFER)$/i.test(line)) {
        return true;
    }
    return /^[A-Z][A-Z0-9&/ ]{1,28}$/.test(line)
        && !/\d{3,}/.test(line)
        && line.length <= 24
        && !/BERHAD|SDN|BHD|STORE|MART|GRAB|FOOD|BANK|ENERGY|ALLOWANCE|LOVENIA|VENNY|CHICKEN|RAMEN|HOSTINGER|AGODA/i.test(line);
}
function islamBoilerplate(line) {
    return /ground floor|al idrus|nombor bebas|toll free|tempoh penyata|statement period|no\.?\s*akaun|account no|cawangan|produk|qard|matawang|currency|dilindungi|protected by pidm|penyata akaun|account statement|transaksi akaun|account transaction|tarikh|^date$|perkara|description|no\.cek|cheque|pengeluaran|withdrawal|simpanan|^deposit$|baki|^balance$|butir-butir|additional details|segala bilangan|all items and balances|tanda "-" |debit balance|sila maklumkan|change of address|sebarang cek|cheques deposited|kuching, sarawak|jalan satok|jalan green|^total$|ending balance|beginning balance/i.test(line);
}

function islamDetailGroups(details) {
    const groups = [];
    let i = 0;
    const n = details.length;
    while (i < n) {
        const line = details[i];
        if (islamIsAmountLine(line)) { i++; continue; }
        if (islamIsFooterLine(line)) break;
        if (details[i + 1] && islamIsFooterLine(details[i + 1]) && /LOVENIA/i.test(line)) break;
        if (/^QR Payment$/i.test(line)) {
            let skip = 0;
            while (i < n && /^QR Payment$/i.test(details[i]) && skip < 2) { i++; skip++; }
            while (i < n && (islamIsAmountLine(details[i]) || islamRef(details[i]))) i++;
            if (i < n && !islamIsFooterLine(details[i]) && !/^(QR Payment|Fund Transfer)$/i.test(details[i]) && !islamIsAmountLine(details[i])) {
                groups.push(details[i]); i++;
            } else groups.push('Unknown');
            continue;
        }
        if (/^(Fund Transfer|Outward ACH|Inward ACH)$/i.test(line)) {
            i++;
            while (i < n && (islamIsAmountLine(details[i]) || islamRef(details[i]))) i++;
            if (i < n && !islamIsFooterLine(details[i]) && !/^(QR Payment|Fund Transfer)$/i.test(details[i]) && !islamIsAmountLine(details[i])) {
                groups.push(details[i]); i++;
            } else groups.push('Unknown');
            continue;
        }
        if (islamAcquirerLine(line)) {
            i++;
            while (i < n && (islamIsAmountLine(details[i]) || islamRef(details[i]))) i++;
            if (i < n && !islamIsFooterLine(details[i]) && !islamAcquirerLine(details[i]) && !/^(QR Payment|Fund Transfer|weekly allowance|seb allowance)$/i.test(details[i]) && !islamIsAmountLine(details[i])) {
                groups.push(details[i]); i++;
            } else groups.push(line);
            continue;
        }
        if (islamDetailLabel(line) && details[i + 1] && islamPersonLine(details[i + 1])) {
            groups.push((line + ' ' + details[i + 1]).trim());
            i += 2;
            continue;
        }
        if (/^Subscription /i.test(line)) { i++; continue; }
        groups.push(line);
        i++;
    }
    return groups;
}

function islamCollectPage(page) {
    const lines = [];
    for (const raw of page.split(/\r?\n/)) {
        let line = raw.replace(/\s+/g, ' ').trim().replace(/[\u2212\u2013\u2014]/g, '-');
        if (line) lines.push(line);
    }
    const dates = [], descs = [], amounts = [], details = [];
    let phase = 'head';
    for (const line of lines) {
        if (/^\d{1,2}\/\d{2}\/\d{2}$/.test(line)) { dates.push(line); phase = 'dates'; continue; }
        if (phase === 'head') continue;
        if (islamTxnType(line) && phase !== 'money' && phase !== 'tail') { descs.push(line.toUpperCase()); phase = 'descs'; continue; }
        if (islamIsAmountLine(line)) { amounts.push(line); phase = 'money'; continue; }
        if (islamBoilerplate(line) || islamRef(line)) continue;
        if (phase === 'money' || phase === 'tail') {
            phase = 'tail';
            if (line.length >= 3 && !islamIsAmountLine(line)) details.push(line);
        }
    }
    if (descs.length < 1 || amounts.length < 2) return null;
    const hasBf = (descs[0] || '').toUpperCase() === 'B/F';
    let types = hasBf ? descs.slice(1) : descs.slice();
    if (dates.length === types.length + 1 && hasBf) dates.shift();
    const n = Math.min(dates.length, types.length);
    if (!n || amounts.length < n * 2) return null;
    const hasTotal = lines.some((l) => l.toUpperCase() === 'TOTAL');
    let withdrawals = amounts.slice(0, n);
    let deposits, rest, totalWithdrawal = null, totalDeposit = null;
    if (hasTotal && amounts.length >= n * 2 + 2) {
        totalWithdrawal = islamNum(amounts[n]);
        deposits = amounts.slice(n + 1, n + 1 + n);
        totalDeposit = islamNum(amounts[n * 2 + 1]);
        rest = amounts.slice(n * 2 + 2);
    } else {
        deposits = amounts.slice(n, n * 2);
        rest = amounts.slice(n * 2);
    }
    return { has_bf: hasBf, n, dates: dates.slice(0, n), types: types.slice(0, n), withdrawals, deposits, rest, total_withdrawal: totalWithdrawal, total_deposit: totalDeposit, groups: islamDetailGroups(details) };
}

function islamPickReceiver(groups, type, direction, amount) {
    const familyTxn = /TRANSFER|SERVICE CHARGE/i.test(type);
    const wantMum = familyTxn && direction === 'in' && amount >= 180 && amount <= 270;
    for (let j = 0; j < groups.length; j++) {
        const group = groups[j];
        if (islamIsAmountLine(group)) continue;
        const family = islamIsFamilyDetail(group);
        if (family && !familyTxn) continue;
        if (!family && wantMum) continue;
        groups.splice(j, 1);
        return group || 'Unknown';
    }
    const receiver = groups[0] || 'Unknown';
    if (groups.length) groups.splice(0, 1);
    return receiver || 'Unknown';
}

function islamUnshiftStrayDeposits(withdrawals, deposits) {
    for (let i = 1; i < withdrawals.length; i++) {
        const prevOut = islamNum(withdrawals[i - 1] || '0');
        const prevIn = islamNum(deposits[i - 1] || '0');
        const outAmt = islamNum(withdrawals[i] || '0');
        const inAmt = islamNum(deposits[i] || '0');
        if (prevOut < 0.01 && prevIn < 0.01 && outAmt >= 0.01 && inAmt >= 0.01) {
            deposits[i - 1] = deposits[i];
            deposits[i] = '0.00';
        }
    }
}

function islamColumnAmount(type, outAmt, inAmt) {
    const out = outAmt >= 0.01, inn = inAmt >= 0.01;
    if (out && !inn) return ['out', outAmt];
    if (inn && !out) return ['in', inAmt];
    if (!out && !inn) return null;
    const debit = /DEBIT|PURCHASE|WITHDRAWAL|CHARGE|\bQR\b|FPX DEBIT/i.test(type);
    const credit = /CREDIT|CR TFR|HIBAH|INWARD|SAL\/MISC|TRANSFER/i.test(type);
    if (debit && !credit) return ['out', outAmt];
    if (credit && !debit) return ['in', inAmt];
    return outAmt >= inAmt ? ['out', outAmt] : ['in', inAmt];
}

function islamRowsFromPack(pack) {
    const n = pack.n;
    const withdrawals = pack.withdrawals.slice();
    const deposits = pack.deposits.slice();
    islamUnshiftStrayDeposits(withdrawals, deposits);
    const groups = pack.groups.slice();
    const totW = pack.total_withdrawal;
    const totD = pack.total_deposit;
    const rows = [];
    for (let i = 0; i < n; i++) {
        const date = parseDate(pack.dates[i] || '');
        let outAmt = islamNum(withdrawals[i] || '0');
        let inAmt = islamNum(deposits[i] || '0');
        const type = pack.types[i] || '';
        if (totW != null && Math.abs(outAmt - totW) < 0.04 && inAmt >= 0.01) outAmt = 0;
        if (totD != null && Math.abs(inAmt - totD) < 0.04 && outAmt >= 0.01) inAmt = 0;
        if (totW != null && Math.abs(inAmt - totW) < 0.04) inAmt = 0;
        if (totD != null && Math.abs(outAmt - totD) < 0.04) outAmt = 0;
        const picked = islamColumnAmount(type, outAmt, inAmt);
        if (!picked || !date) continue;
        const [direction, amount] = picked;
        if (totW != null && Math.abs(amount - totW) < 0.04) continue;
        if (totD != null && Math.abs(amount - totD) < 0.04) continue;
        let receiver = islamPickReceiver(groups, type, direction, amount);
        if (/ENDING BALANCE|BEGINNING BALANCE|\bTOTAL\b/i.test(receiver) || islamIsAmountLine(receiver)) receiver = 'Unknown';
        rows.push({ date, description: receiver, receiver, amount, direction });
    }
    return rows;
}

function parseBankIslamText(text) {
    let periodStart = null, periodEnd = null;
    const p = text.match(/(\d{1,2}\/\d{1,2}\/\d{4})\s*[-–]\s*(\d{1,2}\/\d{1,2}\/\d{4})/);
    if (p) {
        periodStart = parseDate(p[1]);
        periodEnd = parseDate(p[2]);
    }
    const pages = text.split(/(?:PAGE\s+\d+|--\s*\d+\s+of\s+\d+\s*--)/i);
    let rows = [];
    let opening = null, closing = null, totalWithdrawal = null, totalDeposit = null;
    for (const page of pages) {
        const pack = islamCollectPage(page);
        if (!pack) continue;
        if (pack.has_bf && pack.rest.length) opening = opening ?? islamNum(pack.rest[0]);
        if (pack.total_withdrawal != null) totalWithdrawal = pack.total_withdrawal;
        if (pack.total_deposit != null) totalDeposit = pack.total_deposit;
        if (pack.rest.length) {
            for (let k = pack.rest.length - 1; k >= 0; k--) {
                const end = islamNum(pack.rest[k]);
                if (end >= 0.005) { closing = end; break; }
            }
        }
        rows = rows.concat(islamRowsFromPack(pack));
    }
    return { period_start: periodStart, period_end: periodEnd, rows, opening_balance: opening, closing_balance: closing, total_withdrawal: totalWithdrawal, total_deposit: totalDeposit };
}

function parsePdfBuffer(buffer) {
    const text = extractPdfText(buffer);
    if (/tempoh penyata|transaksi akaun|pengeluaran|qard sa/i.test(text)) {
        const islam = parseBankIslamText(text);
        if (islam.rows.length) return islam;
    }
    throw new Error('Found text in the PDF but no clear transaction rows.');
}
