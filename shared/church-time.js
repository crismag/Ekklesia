/*
 * Church time in the browser (window.EkklesiaTime).
 *
 * Every date and time Ekklesia shows is the church's local time, whoever is
 * looking and wherever they are: a 10:00 service is 10:00 on every screen.
 * The browser's own time zone is never used to decide a date.
 *
 * Dates handed out by these helpers are "church clock" Date objects: their
 * local fields (getFullYear, getHours, toLocaleTimeString...) read as the
 * church's wall-clock time. Build day grids from them with local fields, and
 * name a day with key(), never toISOString(), which turns it into UTC.
 *
 * The page embeds this file through App\Core\ChurchTime::script(), which
 * fills in the church's zone.
 */
(function (root) {
    'use strict';

    var zone = __CHURCH_TIME_ZONE__;
    var pattern = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/;
    var parts = null;

    try {
        parts = new Intl.DateTimeFormat('en-US', {
            timeZone: zone, hourCycle: 'h23',
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    } catch (e) {
        parts = null; // unknown zone: fall back to the browser's clock
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** The church's wall-clock time at an instant (milliseconds). */
    function wall(ms) {
        if (!parts) { return new Date(ms); }
        var f = {};
        parts.formatToParts(new Date(ms)).forEach(function (p) { f[p.type] = p.value; });
        return new Date(+f.year, +f.month - 1, +f.day, +f.hour % 24, +f.minute, +f.second);
    }

    var api = {
        zone: zone,

        /**
         * A date or time from the server, as church clock time.
         * "2026-10-11" and "2026-10-11 10:00:00" are already church time and
         * are taken as written. A value carrying a zone ("...-04:00", "...Z")
         * is an instant, shown on the church's clock.
         */
        parse: function (value) {
            if (value === null || value === undefined || value === '') { return null; }
            if (value instanceof Date) { return isNaN(value.getTime()) ? null : new Date(value.getTime()); }
            var s = String(value).trim();
            var m = pattern.exec(s);
            if (m && !m[7]) {
                return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
            }
            var t = Date.parse(m ? s.replace(' ', 'T') : s);
            return isNaN(t) ? null : wall(t);
        },

        /** The church's current date and time. */
        now: function () { return wall(Date.now()); },

        /** Today's date at the church, "YYYY-MM-DD". */
        today: function () { return api.key(api.now()); },

        /** Midnight today at the church. */
        startOfToday: function () {
            var n = api.now();
            return new Date(n.getFullYear(), n.getMonth(), n.getDate());
        },

        /** The "YYYY-MM-DD" name of a church-clock date. */
        key: function (date) {
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
        }
    };

    root.EkklesiaTime = api;
})(typeof window !== 'undefined' ? window : globalThis);
