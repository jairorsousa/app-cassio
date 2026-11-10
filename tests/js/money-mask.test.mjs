import { parseToCents as p, formatCents as f, centsToModel as m, displayCents as d, applyTyped as t } from '../../resources/js/money-mask.js';
import assert from 'node:assert';

const cases = {'1234,56':123456,'1.234,56':123456,'R$ 1.234,56':123456,'R$\u00a01.234,56':123456,'1234.56':123456,'1,234.56':123456,'1234':123400,'1000':100000,'1000.5':100050,'1.000,00':100000,'R$ 1.000,00':100000,'1.234':123400,'1.234.567':123456700,'0.5':50,'12,3':1230,'-1.234,56':-123456,'-R$ 10,00':-1000,'1234.567':123457,'0,01':1,'':0,'abc':0,'100.000,00':10000000,'1500.0000':150000};
for (const [k, v] of Object.entries(cases)) assert.strictEqual(p(k), v, k);
assert.strictEqual(f(123456), '1.234,56'); assert.strictEqual(f(-5), '-0,05');
assert.strictEqual(m(123456), '1234.56'); assert.strictEqual(m(-5), '-0.05');
assert.strictEqual(d(100000), 'R$ 1.000,00'); assert.strictEqual(d(-1000), '-R$ 10,00'); assert.strictEqual(d(0, { empty: true }), '');

const empty = { cents: 0, empty: true, negative: false, display: '' };
// Digitar tecla a tecla no fim (caixa eletrônico).
const type = (keys, opts = {}, start = empty) => {
    let st = start;
    const seen = [];
    for (const k of keys) {
        let raw; let caret; let inputType = 'insertText';
        if (k === 'BS') { raw = st.display.slice(0, -1); caret = raw.length; inputType = 'deleteContentBackward'; }
        else { raw = st.display + k; caret = raw.length; }
        st = t(st, raw, caret, { inputType, ...opts });
        seen.push(st.display);
        assert.strictEqual(st.caret, st.display.length, `cursor no fim (${st.display})`);
    }
    return { st, seen };
};
assert.deepStrictEqual(type('100000'.split('')).seen, ['R$ 0,01', 'R$ 0,10', 'R$ 1,00', 'R$ 10,00', 'R$ 100,00', 'R$ 1.000,00']);
const r = type([...'100000', 'BS', 'BS']);
assert.strictEqual(r.st.display, 'R$ 10,00'); assert.strictEqual(r.st.cents, 1000);
assert.strictEqual(type(['5', 'BS']).st.empty, true);
assert.strictEqual(type(['0', '0', '7']).st.display, 'R$ 0,07');
assert.strictEqual(type(['1', 'a', ',', '2']).st.display, 'R$ 0,12');
// Sem sinal: "-" é ignorado; com sinal, alterna.
assert.strictEqual(type(['-', '5']).st.cents, 5);
assert.strictEqual(type(['-', '5', '0'], { allowNegative: true }).st.display, '-R$ 0,50');
assert.strictEqual(type(['-', '5', '0'], { allowNegative: true }).st.cents, -50);
// Selecionar tudo e digitar substitui.
const full = type('12345'.split('')).st;
assert.strictEqual(t(full, '9', 1, { inputType: 'insertText' }).display, 'R$ 0,09');
// Apagar com o cursor logo depois da vírgula apaga o dígito antes dela (não trava).
const v = { cents: 123456, empty: false, negative: false, display: 'R$ 1.234,56' };
const at = v.display.indexOf(',') + 1; // depois da vírgula
const raw = v.display.slice(0, at - 1) + v.display.slice(at);
const res = t(v, raw, at - 1, { inputType: 'deleteContentBackward' });
assert.strictEqual(res.cents, 12356); assert.strictEqual(res.display, 'R$ 123,56');
// Inserir no meio mantém o cursor no mesmo ponto relativo.
const mid = t(v, 'R$ 19.234,56', 5, { inputType: 'insertText' });
assert.strictEqual(mid.display, 'R$ 19.234,56'); assert.strictEqual(mid.caret, 5);
// Limite de dígitos: não cresce além de 15.
let big = type('999999999999999'.split('')).st;
assert.strictEqual(t(big, big.display + '9', big.display.length + 1, {}).cents, big.cents);
console.log('JS money-mask OK');
