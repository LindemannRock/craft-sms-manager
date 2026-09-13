(function(root) {
    'use strict';

    const GSM_BASIC = new Set(Array.from('@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'));
    const GSM_EXTENSION = new Set(Array.from('^{}\\[~]|€\f'));

    function segments(units, singleLimit, multipartLimit) {
        if (units === 0) return 0;
        return units <= singleLimit ? 1 : Math.ceil(units / multipartLimit);
    }

    function analyze(message) {
        const value = String(message || '');
        const characters = Array.from(value);

        if (value === '') {
            return {encoding: 'gsm-7', characters: 0, units: 0, segments: 0};
        }

        let units = 0;
        for (const character of characters) {
            if (GSM_BASIC.has(character)) {
                units += 1;
            } else if (GSM_EXTENSION.has(character)) {
                units += 2;
            } else {
                const ucs2Units = value.length;
                return {
                    encoding: 'ucs-2',
                    characters: characters.length,
                    units: ucs2Units,
                    segments: segments(ucs2Units, 70, 67)
                };
            }
        }

        return {
            encoding: 'gsm-7',
            characters: characters.length,
            units: units,
            segments: segments(units, 160, 153)
        };
    }

    root.lrSmsEncoding = {analyze: analyze};
})(typeof window !== 'undefined' ? window : globalThis);
