import test from 'node:test';
import assert from 'node:assert/strict';
import {fromJalali,toJalali,monthDays,dateOnly,latinDigits,money} from '../../resources/js/finance.js';
test('Nowruz deadline converts to the intended bank date',()=>{assert.equal(fromJalali(1405,1,1),'2026-03-21');assert.deepEqual(toJalali('2026-03-21'),{year:1405,month:1,day:1});});
test('Esfand leap day is accepted only in a leap year',()=>{assert.equal(fromJalali(1403,12,30),'2025-03-20');assert.equal(fromJalali(1404,12,30),'');assert.equal(monthDays(1403,12),30);assert.equal(monthDays(1404,12),29);});
test('Impossible month days never become a different deadline',()=>{assert.equal(fromJalali(1405,7,31),'');assert.equal(toJalali('2026-02-30'),null);assert.equal(toJalali('2026-13-01'),null);assert.equal(toJalali(''),null);});
test('Date-only display does not roll the date backward in Tehran',()=>{assert.match(dateOnly('2026-03-21'),/۱.*فروردین.*۱۴۰۵/);});
test('Persian and Arabic digits normalize without changing account identifiers',()=>{assert.equal(latinDigits('۱۲۳٤٥٦۷۸۹۰'),'1234567890');assert.equal(latinDigits('IR۱۲۳'),'IR123');});
test('Rial amounts are displayed once as toman',()=>{assert.match(money(1000000),/۱۰۰٬۰۰۰.*تومان/);});
