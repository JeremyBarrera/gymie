const DATE_ORDER_COOKIE = 'gymie_date_order';
const HOUR12_COOKIE = 'gymie_hour12';
const LOCALE_COOKIE = 'gymie_device_locale';
const TIMEZONE_COOKIE = 'gymie_device_tz';

function detectOrder() {
    let order = 'dmy';

    try {
        
        
        const parts = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'numeric', day: 'numeric' })
            .formatToParts(new Date(2020, 4, 3));

        const position = {};
        parts.forEach((part, index) => {
            if (part.type === 'month' || part.type === 'day' || part.type === 'year') {
                position[part.type] = index;
            }
        });

        if (position.year !== undefined && position.month !== undefined && position.day !== undefined) {
            if (position.month < position.day && position.day < position.year) {
                order = 'mdy';
            } else if (position.year < position.month && position.year < position.day) {
                order = 'ymd';
            }
        }
    } catch {
        
    }

    return order;
}

function detectHour12() {
    let hour12 = true;

    try {
        hour12 = new Intl.DateTimeFormat(undefined, { hour: 'numeric' }).resolvedOptions().hour12 ?? true;
    } catch {
        
    }

    return hour12;
}

function detectLanguage() {
    try {
        const candidates = Array.isArray(navigator.languages) && navigator.languages.length > 0
            ? navigator.languages
            : [navigator.language];

        for (const candidate of candidates) {
            if (typeof candidate !== 'string' || candidate.trim() === '') {
                continue;
            }

            
            
            const primary = candidate.trim().split(/[-_]/)[0].toLowerCase();

            if (primary !== '') {
                return primary;
            }
        }
    } catch {
        
    }

    return '';
}

function detectTimezone() {
    try {
        
        
        
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
        return '';
    }
}

function setCookie(name, value) {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000; SameSite=Lax`;
}

document.addEventListener('DOMContentLoaded', () => {
    setCookie(DATE_ORDER_COOKIE, detectOrder());
    setCookie(HOUR12_COOKIE, detectHour12() ? '1' : '0');

    const language = detectLanguage();
    if (language !== '') {
        setCookie(LOCALE_COOKIE, language);
    }

    const timezone = detectTimezone();
    if (timezone !== '') {
        setCookie(TIMEZONE_COOKIE, timezone);
    }
});