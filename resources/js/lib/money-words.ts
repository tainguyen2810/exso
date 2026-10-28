const digits = [
    'không',
    'một',
    'hai',
    'ba',
    'bốn',
    'năm',
    'sáu',
    'bảy',
    'tám',
    'chín',
];
const groups = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];

/** One group of three digits, e.g. 105 → "một trăm linh năm". */
function readGroup(value: number, full: boolean): string {
    const hundreds = Math.floor(value / 100);
    const tens = Math.floor((value % 100) / 10);
    const ones = value % 10;
    const words: string[] = [];

    if (full || hundreds > 0) {
        words.push(`${digits[hundreds]} trăm`);
    }

    if (tens > 1) {
        words.push(`${digits[tens]} mươi`);
    } else if (tens === 1) {
        words.push('mười');
    } else if (ones > 0 && (full || hundreds > 0)) {
        words.push('linh');
    }

    if (ones > 0) {
        if (ones === 1 && tens > 1) {
            words.push('mốt');
        } else if (ones === 5 && tens > 0) {
            words.push('lăm');
        } else if (ones === 4 && tens > 1) {
            words.push('tư');
        } else {
            words.push(digits[ones]);
        }
    }

    return words.join(' ');
}

/** Amount in Vietnamese words for vouchers: 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng". */
export function moneyInWords(amount: number): string {
    let value = Math.abs(Math.round(amount));

    if (value === 0) {
        return 'Không đồng';
    }

    const parts: number[] = [];

    while (value > 0) {
        parts.push(value % 1000);
        value = Math.floor(value / 1000);
    }

    const words: string[] = [];

    for (let index = parts.length - 1; index >= 0; index--) {
        if (parts[index] === 0) {
            continue;
        }

        words.push(
            readGroup(parts[index], index < parts.length - 1) + groups[index],
        );
    }

    const text = `${words.join(' ')} đồng`;

    return text.charAt(0).toUpperCase() + text.slice(1);
}
