/*
 * cup-draw.js: draw and results UI for the individual cups (3-2-1, Singles,
 * Pairs, Good Friday, Paul/Terry, Memorial and Cap/Sec Cups).
 *
 * Shared by the seven public cup pages and admin-login.html, so it defines a
 * single global (window.CupDraw) and has no nav code: every page keeps its own
 * nav script. Plain browser JavaScript, no libraries and no build step.
 */
(function () {
    'use strict';

    // The seven individual cups, in the order the API lists them
    const CUPS = [
        { slug: '321-cup', name: '3-2-1 Cup', page: '321cup.html' },
        { slug: 'singles-cup', name: 'Singles Cup', page: 'singlescup.html' },
        { slug: 'pairs-cup', name: 'Pairs Cup', page: 'pairscup.html' },
        { slug: 'good-friday-cup', name: 'Good Friday Cup', page: 'goodfridaycup.html' },
        { slug: 'paul-terry-cup', name: 'Paul/Terry Cup', page: 'paulterrycup.html' },
        { slug: 'memorial-cup', name: 'Memorial Cup', page: 'memorialcup.html' },
        { slug: 'cap-sec-cup', name: 'Cap/Sec Cup', page: 'capseccup.html' }
    ];

    const DIVISIONS = [
        { key: 'premier', label: 'Premier Division' },
        { key: 'a', label: 'A Division' },
        { key: 'combined', label: 'Combined' }
    ];

    const FORMAT_NAMES = { singles: 'Singles', pairs: 'Pairs', trebles: 'Trebles' };
    const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const DOT = ' \u00b7 ';             // middle dot between items on one line
    const AUTO_BRACKET_WIDTH = 760;     // view 'auto': the tree from this container width, by round below it
    const REFRESH_MS = 60000;           // public pages re-check an unfinished draw once a minute

    const PLATE_NOTE = 'First-round losers play on for the losers bracket.';
    const NO_PLATE_NOTE = 'No losers bracket: there was only one first-round match.';
    const MSG_LOADING = 'Loading the draw...';
    const MSG_EMPTY = 'No draw has been made yet. Once the league admin makes the draw it will appear here.';
    const MSG_FAILED = 'The draw could not be loaded right now. Please try again later.';

    // render() state per container (draw, view, selected round, tree scroll), so
    // re-rendering the same draw keeps whatever the viewer was looking at
    const renderStates = new WeakMap();
    const boundContainers = new WeakSet();
    // mountPage() state per page root
    const pageStates = new WeakMap();
    const boundRoots = new WeakSet();

    // ---------- small helpers ----------

    // Names are free text typed by the admin: escape everything that goes into HTML
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // The API sends ids and scores as ints; normalise anyway so === comparisons are safe
    function toNum(v) {
        if (v === null || v === undefined || v === '' || typeof v === 'boolean') return null;
        const n = Number(v);
        return Number.isFinite(n) ? n : null;
    }

    function isOn(v) {
        return v === true || v === 1 || v === '1';
    }

    function divisionLabel(key) {
        const d = DIVISIONS.find(x => x.key === key);
        return d ? d.label : (key == null ? '' : String(key));
    }

    function roundNameForSize(n) {
        if (n === 2) return 'Final';
        if (n === 4) return 'Semi Final';
        if (n === 8) return 'Quarter Final';
        return 'Last ' + n;
    }

    // "2026-11-03" -> "Tue 3 Nov 2026" (fixed English names, no time zone shift)
    function formatDrawDate(ymd) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd == null ? '' : ymd));
        if (!m) return '';
        const y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
        const dt = new Date(Date.UTC(y, mo - 1, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) return '';
        return DAY_NAMES[dt.getUTCDay()] + ' ' + d + ' ' + MONTH_NAMES[mo - 1] + ' ' + y;
    }

    function entryName(entry) {
        if (!entry) return '';
        if (entry.name) return String(entry.name);
        const names = Array.isArray(entry.players) ? entry.players.map(p => p && p.player_name).filter(Boolean) : [];
        return names.length ? names.join(' & ') : 'Unknown entry';
    }

    function entryTeam(entry) {
        return entry && entry.team_name ? String(entry.team_name) : '';
    }

    function readStoredView() {
        try {
            const v = window.localStorage.getItem('cupDrawView:' + window.location.pathname);
            return (v === 'bracket' || v === 'rounds') ? v : null;
        } catch (e) {
            return null;
        }
    }

    function storeView(view) {
        try {
            window.localStorage.setItem('cupDrawView:' + window.location.pathname, view);
        } catch (e) {
            // Storage blocked (private mode, settings): the choice just is not remembered
        }
    }

    // ---------- API ----------

    async function fetchDraws(cupSlug) {
        let url = 'api.php?endpoint=comp-draws';
        if (cupSlug) url += '&cup=' + encodeURIComponent(cupSlug);
        let res;
        try {
            res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        } catch (e) {
            return { success: false, error: 'Could not reach the server. Check the connection and try again.' };
        }
        try {
            const data = await res.json();
            if (data && typeof data === 'object' && !Array.isArray(data)) return data;
        } catch (e) {
            // not JSON: reported below
        }
        return { success: false, error: 'The server sent a reply that could not be read (HTTP ' + res.status + ').' };
    }

    // e.g. "Pairs, Best of 5 legs, 12 entries, Random partners, Losers bracket, Drawn Tue 3 Nov 2026",
    // with a middle dot between the items
    function formatLine(draw) {
        if (!draw || typeof draw !== 'object') return '';
        const parts = [];
        if (FORMAT_NAMES[draw.format]) parts.push(FORMAT_NAMES[draw.format]);
        const bestOf = toNum(draw.best_of);
        if (bestOf) parts.push('Best of ' + bestOf + ' legs');
        const n = Array.isArray(draw.entries) ? draw.entries.length : 0;
        parts.push(n + (n === 1 ? ' entry' : ' entries'));
        if ((draw.format === 'pairs' || draw.format === 'trebles') && draw.partner_mode === 'random') parts.push('Random partners');
        const plateBuilt = toNum(draw.plate_rounds) > 0 || (Array.isArray(draw.matches) && draw.matches.some(m => m && m.bracket === 'plate'));
        if (isOn(draw.has_plate) && plateBuilt) parts.push('Losers bracket');
        const drawn = formatDrawDate(draw.draw_date);
        if (drawn) parts.push('Drawn ' + drawn);
        return parts.join(DOT);
    }

    // ---------- draw model ----------

    // Normalises the API's draw into rounds, match numbers (M1.., L1..) and,
    // for every slot, the match that feeds it, so empty slots can say
    // "Winner of M5" / "Loser of M5" / "Winner of L2"
    function buildModel(draw) {
        const entries = new Map();
        (Array.isArray(draw.entries) ? draw.entries : []).forEach(e => {
            const id = e ? toNum(e.entry_id) : null;
            if (id !== null) entries.set(id, e);
        });

        const matches = (Array.isArray(draw.matches) ? draw.matches : [])
            .filter(raw => raw && typeof raw === 'object')
            .map(raw => ({
                raw: raw,
                id: toNum(raw.match_id),
                bracket: raw.bracket === 'plate' ? 'plate' : 'main',
                round: Math.max(1, toNum(raw.round_no) || 1),
                pos: Math.max(0, (toNum(raw.match_no) || 1) - 1),
                home: toNum(raw.home_entry_id),
                away: toNum(raw.away_entry_id),
                winner: toNum(raw.winner_entry_id),
                homeLegs: toNum(raw.home_score),
                awayLegs: toNum(raw.away_score),
                bye: isOn(raw.is_bye),
                next: toNum(raw.next_match_id),
                nextSlot: raw.next_slot,
                loserNext: toNum(raw.loser_next_match_id),
                loserSlot: raw.loser_next_slot,
                roundName: typeof raw.round_name === 'string' ? raw.round_name : '',
                label: '',
                isFinal: false,
                feed: { home: null, away: null }
            }));
        matches.sort((a, b) => (a.bracket === b.bracket ? 0 : (a.bracket === 'main' ? -1 : 1)) ||
            a.round - b.round || a.pos - b.pos);

        // Main matches are M1, M2, ... (round 1 by match_no, then round 2, ...); losers bracket L1, L2, ...
        const byId = new Map();
        let mainNo = 0, plateNo = 0;
        matches.forEach(m => {
            m.label = m.bracket === 'main' ? 'M' + (++mainNo) : 'L' + (++plateNo);
            if (m.id !== null) byId.set(m.id, m);
        });
        matches.forEach(m => {
            const next = m.next !== null ? byId.get(m.next) : null;
            if (next && (m.nextSlot === 'home' || m.nextSlot === 'away')) next.feed[m.nextSlot] = { from: m, kind: 'winner' };
            const drop = m.loserNext !== null ? byId.get(m.loserNext) : null;
            if (drop && (m.loserSlot === 'home' || m.loserSlot === 'away')) drop.feed[m.loserSlot] = { from: m, kind: 'loser' };
        });

        const rounds = [];
        const roundByKey = new Map();
        matches.forEach(m => {
            const key = m.bracket + '-' + m.round;
            let r = roundByKey.get(key);
            if (!r) {
                r = { key: key, bracket: m.bracket, round: m.round, name: '', matches: [] };
                roundByKey.set(key, r);
                rounds.push(r);
            }
            r.matches.push(m);
        });
        const mainRounds = rounds.filter(r => r.bracket === 'main');
        const plateRounds = rounds.filter(r => r.bracket === 'plate');
        [mainRounds, plateRounds].forEach(list => {
            const total = list.length ? list[list.length - 1].round : 0;
            list.forEach(r => {
                const named = r.matches.find(m => m.roundName);
                r.name = named ? named.roundName : roundNameForSize(Math.pow(2, total - r.round + 1));
            });
        });

        const lastMain = mainRounds.length ? mainRounds[mainRounds.length - 1] : null;
        const lastPlate = plateRounds.length ? plateRounds[plateRounds.length - 1] : null;
        const mainFinal = lastMain ? lastMain.matches[0] : null;
        const plateFinal = lastPlate ? lastPlate.matches[0] : null;
        if (mainFinal) mainFinal.isFinal = true;
        if (plateFinal) plateFinal.isFinal = true;

        // Default round: the earliest one (main first, then losers) still waiting for a result;
        // once everything is decided, the main Final
        const ordered = mainRounds.concat(plateRounds);
        const defaultRound = ordered.find(r => r.matches.some(m => m.winner === null)) || lastMain || ordered[0] || null;

        const bestOf = toNum(draw.best_of);
        return {
            entries: entries,
            matches: matches,
            mainRounds: mainRounds,
            plateRounds: plateRounds,
            roundByKey: roundByKey,
            defaultRound: defaultRound,
            championId: mainFinal ? mainFinal.winner : toNum(draw.champion_entry_id),
            plateChampionId: plateFinal ? plateFinal.winner : null,
            complete: !!mainFinal && mainFinal.winner !== null && (!plateFinal || plateFinal.winner !== null),
            bestOf: bestOf && bestOf > 0 && bestOf % 2 === 1 ? bestOf : 3,
            format: FORMAT_NAMES[draw.format] ? draw.format : 'singles'
        };
    }

    // Text for a slot that has no entrant yet
    function emptySlotText(m, side, depth) {
        if (m.bye && side === 'away') return 'Bye';
        const feed = m.feed[side];
        if (!feed) return 'To be decided';
        if (feed.kind === 'loser') return 'Loser of ' + feed.from.label;
        // A losers bracket bye only passes its entrant on, so name where that entrant comes from
        const src = feed.from;
        if (src.bye && src.winner === null && src.home === null && depth < 4) return emptySlotText(src, 'home', depth + 1);
        return 'Winner of ' + src.label;
    }

    function sideInfo(model, m, side) {
        const id = side === 'home' ? m.home : m.away;
        const entry = id === null ? null : (model.entries.get(id) || { name: 'Unknown entry', team_name: null });
        const decided = m.winner !== null && !m.bye;
        let legs = null;
        if (!m.bye && m.homeLegs !== null && m.awayLegs !== null) legs = side === 'home' ? m.homeLegs : m.awayLegs;
        return {
            entry: entry,
            won: decided && id !== null && id === m.winner,
            lost: decided && id !== null && id !== m.winner,
            legs: legs,
            empty: entry ? '' : emptySlotText(m, side, 0)
        };
    }

    function stateClass(s) {
        return s.won ? ' cd-win' : (s.lost ? ' cd-lose' : '');
    }

    // Tree layout: column = round; a round r match at position p spans 2^(r-1) first-round rows
    // (row 1 holds the round titles), so every match sits level with the pair that feeds it
    function gridPlace(round, pos) {
        const span = Math.pow(2, round - 1);
        return { col: round, row: 2 + pos * span, span: span };
    }

    // ---------- HTML pieces ----------

    function pickButton(m, side, cls, name, title, inner) {
        return `<button type="button" class="${cls} cd-pick" data-cd-act="pick" data-cd-mid="${m.id}" data-cd-side="${side}" data-cd-key="pick-${m.id}-${side}" aria-label="${esc('Mark ' + name + ' as the winner')}" title="${esc(title)}">${inner}</button>`;
    }

    // Admin: leg score (written from the home side, valid for the current winner) and Undo
    function controlsHtml(model, m) {
        if (m.bye || m.winner === null || m.home === null || m.away === null) return '';
        const winSide = m.winner === m.home ? 'home' : (m.winner === m.away ? 'away' : '');
        if (!winSide) return '';
        const need = Math.ceil(model.bestOf / 2);
        const values = [];
        for (let lost = 0; lost < need; lost++) values.push(winSide === 'home' ? need + '-' + lost : lost + '-' + need);
        const current = m.homeLegs !== null && m.awayLegs !== null ? m.homeLegs + '-' + m.awayLegs : '';
        if (current && values.indexOf(current) === -1) values.unshift(current);
        const options = `<option value=""${current ? '' : ' selected'}>Score</option>` +
            values.map(v => `<option value="${v}"${v === current ? ' selected' : ''}>${v}</option>`).join('');
        return `<div class="cd-ctrl">` +
            `<select class="cd-legsel" data-cd-act="score" data-cd-mid="${m.id}" data-cd-cur="${current}" data-cd-key="score-${m.id}" aria-label="Leg score for ${esc(m.label)}">${options}</select>` +
            `<button type="button" class="cd-undo" data-cd-act="undo" data-cd-mid="${m.id}" data-cd-key="undo-${m.id}" aria-label="Undo the result of ${esc(m.label)}">Undo</button>` +
            `</div>`;
    }

    // Tree card row: one line (name, then the team after a middle dot), ellipsis, full text in the title
    function treeRow(model, m, side, canPick) {
        const s = sideInfo(model, m, side);
        const pos = side === 'home' ? ' cd-r1' : ' cd-r2';
        if (!s.entry) return `<div class="cd-row cd-tbd${pos}"><span class="cd-line">${esc(s.empty)}</span></div>`;
        const name = entryName(s.entry);
        const team = entryTeam(s.entry);
        const full = team ? name + DOT + team : name;
        const inner = `<span class="cd-line"><span class="cd-n">${esc(name)}</span>${team ? `<span class="cd-t">${esc(DOT + team)}</span>` : ''}</span>` +
            (s.won ? '<span class="cd-sr"> (winner)</span>' : '') +
            (s.legs !== null ? `<span class="cd-legs">${s.legs}</span>` : '');
        const cls = 'cd-row' + pos + stateClass(s);
        // The current winner is not a button: clicking it again would only wipe the leg score
        if (canPick && !s.won) return pickButton(m, side, cls, name, full, inner);
        return `<div class="${cls}" title="${esc(full)}">${inner}</div>`;
    }

    function treeCard(model, m, admin) {
        const canPick = admin && !m.bye && m.home !== null && m.away !== null;
        const controls = admin ? controlsHtml(model, m) : '';
        let cls = 'cd-tcard';
        if (m.bye) cls += ' cd-byecard';
        if (m.isFinal) cls += ' cd-final';
        if (controls) cls += ' cd-has-ctrl';
        return `<div class="${cls}"><span class="cd-tnum">${esc(m.label)}</span>` +
            treeRow(model, m, 'home', canPick) + treeRow(model, m, 'away', canPick) + controls + '</div>';
    }

    function treeHtml(model, rounds, bracket, admin) {
        if (!rounds.length) return '';
        const total = rounds[rounds.length - 1].round;
        const first = rounds.find(r => r.round === 1);
        const firstCount = Math.max(Math.pow(2, total - 1), first ? first.matches.length : 0);
        let html = `<div class="cd-scroll" data-cd-scroll="${bracket}">` +
            `<div class="cd-grid cd-fmt-${model.format}" style="grid-template-columns:repeat(${total}, minmax(var(--cd-col), var(--cd-col-max)));grid-template-rows:auto repeat(${firstCount}, var(--cd-row))">`;
        rounds.forEach(r => {
            html += `<div class="cd-rtitle" style="grid-column:${r.round};grid-row:1">${esc(r.name)}</div>`;
            r.matches.forEach(m => {
                const g = gridPlace(m.round, m.pos);
                let cls = 'cd-cell ' + (m.pos % 2 === 0 ? 'cd-top' : 'cd-bot');
                if (m.round === 1) cls += ' cd-first';
                if (m.round === total) cls += ' cd-last';
                html += `<div class="${cls}" style="grid-column:${g.col};grid-row:${g.row} / span ${g.span}">${treeCard(model, m, admin)}</div>`;
            });
        });
        return html + '</div></div>';
    }

    function bracketHtml(model, admin) {
        let html = treeHtml(model, model.mainRounds, 'main', admin);
        if (model.plateRounds.length) {
            html += `<h4 class="cd-plate-head">Losers Bracket</h4><p class="cd-note">${PLATE_NOTE}</p>` +
                treeHtml(model, model.plateRounds, 'plate', admin);
        }
        return html || '<p class="cd-empty">This draw has no matches.</p>';
    }

    // By-round card row: the name wraps, the team sits on its own muted line
    function listSide(model, m, side, canPick) {
        const s = sideInfo(model, m, side);
        if (!s.entry) return `<div class="cd-side cd-tbd"><span class="cd-who">${esc(s.empty)}</span></div>`;
        const name = entryName(s.entry);
        const team = entryTeam(s.entry);
        const inner = `<span class="cd-who"><span class="cd-n">${esc(name)}</span>${team ? `<span class="cd-t">${esc(team)}</span>` : ''}` +
            (s.won ? '<span class="cd-sr"> (winner)</span>' : '') + '</span>' +
            (s.legs !== null ? `<span class="cd-legs">${s.legs}</span>` : '');
        const cls = 'cd-side' + stateClass(s);
        if (canPick && !s.won) return pickButton(m, side, cls, name, team ? name + DOT + team : name, inner);
        return `<div class="${cls}">${inner}</div>`;
    }

    function listItem(model, m, admin) {
        if (m.bye) {
            const s = sideInfo(model, m, 'home');
            const who = s.entry ? entryName(s.entry) : s.empty;
            const team = s.entry ? entryTeam(s.entry) : '';
            return `<div class="cd-byerow" title="${esc(team ? who + DOT + team : who)}"><span class="cd-mnum">${esc(m.label)}</span><span class="cd-byetext">${esc(who + ': bye')}</span></div>`;
        }
        const canPick = admin && m.home !== null && m.away !== null;
        const hint = canPick && m.winner === null ? '<span class="cd-hint">Pick the winner</span>' : '';
        let cls = 'cd-mcard';
        if (m.winner !== null) cls += ' cd-done';
        if (m.isFinal) cls += ' cd-final';
        return `<div class="${cls}"><div class="cd-mhead"><span class="cd-mnum">${esc(m.label)}</span>${hint}</div>` +
            listSide(model, m, 'home', canPick) + listSide(model, m, 'away', canPick) +
            (admin ? controlsHtml(model, m) : '') + '</div>';
    }

    function roundsHtml(st, admin) {
        const model = st.model;
        const all = model.mainRounds.concat(model.plateRounds);
        if (!all.length) return '<p class="cd-empty">This draw has no matches.</p>';
        const current = model.roundByKey.get(st.round) || model.defaultRound;
        const chips = all.map(r => {
            const on = r === current;
            const label = r.bracket === 'plate' ? 'Losers: ' + r.name : r.name;
            return `<button type="button" class="cd-rchip${r.bracket === 'plate' ? ' cd-rchip-plate' : ''}${on ? ' cd-on' : ''}" data-cd-act="round" data-cd-round="${r.key}" data-cd-key="round-${r.key}" aria-pressed="${on}">${esc(label)}</button>`;
        }).join('');
        const title = current.bracket === 'plate' ? 'Losers Bracket: ' + current.name : current.name;
        return `<div class="cd-chips" role="group" aria-label="Rounds">${chips}</div>` +
            `<h4 class="cd-rhead">${esc(title)}</h4>` +
            (current.bracket === 'plate' ? `<p class="cd-note">${PLATE_NOTE}</p>` : '') +
            `<div class="cd-list">${current.matches.map(m => listItem(model, m, admin)).join('')}</div>`;
    }

    function viewButton(view, label, current) {
        const on = view === current;
        return `<button type="button" class="cd-vbtn${on ? ' cd-on' : ''}" data-cd-act="view" data-cd-view="${view}" data-cd-key="view-${view}" aria-pressed="${on}">${label}</button>`;
    }

    function winnerBanner(model, entryId, prefix, extraClass) {
        const entry = entryId === null ? null : model.entries.get(entryId);
        if (!entry) return '';
        const team = entryTeam(entry);
        return `<div class="cd-champ${extraClass}"><div class="cd-champ-name">${esc(prefix + entryName(entry))}</div>` +
            (team ? `<div class="cd-champ-team">${esc(team)}</div>` : '') + '</div>';
    }

    function headHtml(st) {
        const model = st.model;
        const draw = st.draw;
        const complete = draw.complete === undefined ? model.complete : isOn(draw.complete);
        let html = '<div class="cd-head">' +
            (complete ? '<span class="cd-state cd-state-done">Complete</span>' : '<span class="cd-state cd-state-live">In progress</span>') +
            '<div class="cd-views" role="group" aria-label="Show the draw as">' +
            viewButton('bracket', 'Bracket', st.view) + viewButton('rounds', 'By round', st.view) +
            '</div></div>';
        html += winnerBanner(model, model.championId, 'Winner: ', '');
        html += winnerBanner(model, model.plateChampionId, 'Losers Bracket winner: ', ' cd-champ-sm');
        if (isOn(draw.has_plate) && !model.plateRounds.length) html += `<p class="cd-note">${NO_PLATE_NOTE}</p>`;
        return html;
    }

    // ---------- render ----------

    // view 'auto': the tree when the container is wide enough, otherwise by round.
    // A choice the viewer made on this page earlier wins over 'auto'.
    function chooseView(container, wanted) {
        if (wanted === 'bracket' || wanted === 'rounds') return { view: wanted, source: 'opts' };
        const stored = readStoredView();
        if (stored) return { view: stored, source: 'stored' };
        const width = container.clientWidth || 0;
        if (width > 0) return { view: width >= AUTO_BRACKET_WIDTH ? 'bracket' : 'rounds', source: 'auto' };
        // Hidden (an inactive tab or pane) so it cannot be measured: by round works at any width;
        // decide again on the next render
        return { view: 'rounds', source: 'guess' };
    }

    // Remember the tree scroll positions and the focused control before the DOM is replaced
    function captureUi(container, st) {
        Array.prototype.forEach.call(container.querySelectorAll('[data-cd-scroll]'), el => {
            st.scroll[el.getAttribute('data-cd-scroll')] = el.scrollLeft;
        });
        st.focusKey = null;
        const active = document.activeElement;
        if (active && active !== container && typeof active.getAttribute === 'function' && container.contains(active)) {
            st.focusKey = active.getAttribute('data-cd-key');
        }
    }

    function restoreFocus(container, key) {
        if (!key) return;
        const keys = [key];
        // After a pick or an undo that control is gone: move to the nearest one for the same match
        const pick = /^pick-(\d+)-/.exec(key);
        if (pick) keys.push('score-' + pick[1], 'pick-' + pick[1] + '-home', 'pick-' + pick[1] + '-away');
        const other = /^(?:undo|score)-(\d+)$/.exec(key);
        if (other) keys.push('pick-' + other[1] + '-home', 'pick-' + other[1] + '-away');
        for (let i = 0; i < keys.length; i++) {
            const el = container.querySelector('[data-cd-key="' + keys[i] + '"]');
            if (el && typeof el.focus === 'function') {
                try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
                return;
            }
        }
    }

    function paint(container, st) {
        const admin = !!st.opts.admin;
        container.innerHTML = `<div class="cd-ui${admin ? ' cd-admin' : ''}">` + headHtml(st) +
            (st.view === 'bracket' ? bracketHtml(st.model, admin) : roundsHtml(st, admin)) + '</div>';
        if (st.view === 'bracket') {
            Array.prototype.forEach.call(container.querySelectorAll('[data-cd-scroll]'), el => {
                const x = st.scroll[el.getAttribute('data-cd-scroll')];
                if (x > 0) el.scrollLeft = x;
            });
        }
        restoreFocus(container, st.focusKey);
        st.focusKey = null;
    }

    function findMatch(draw, id) {
        if (id === null || !draw || !Array.isArray(draw.matches)) return null;
        return draw.matches.find(m => m && toNum(m.match_id) === id) || null;
    }

    function onContainerClick(container, e) {
        const st = renderStates.get(container);
        const target = e.target && typeof e.target.closest === 'function' ? e.target.closest('[data-cd-act]') : null;
        if (!st || !target || !container.contains(target)) return;
        const act = target.getAttribute('data-cd-act');
        if (act === 'score') return; // the leg score select reports on change
        if (container.classList.contains('cd-busy')) {
            e.preventDefault();
            return;
        }
        if (act === 'view') {
            const view = target.getAttribute('data-cd-view');
            if ((view === 'bracket' || view === 'rounds') && view !== st.view) {
                captureUi(container, st);
                st.view = view;
                st.viewSource = 'user';
                storeView(view);
                paint(container, st);
            }
            return;
        }
        if (act === 'round') {
            const key = target.getAttribute('data-cd-round');
            if (st.model.roundByKey.has(key) && key !== st.round) {
                captureUi(container, st);
                st.round = key;
                paint(container, st);
            }
            return;
        }
        const match = findMatch(st.draw, toNum(target.getAttribute('data-cd-mid')));
        if (!match) return;
        if (act === 'pick') {
            const side = target.getAttribute('data-cd-side');
            if ((side === 'home' || side === 'away') && typeof st.opts.onPick === 'function') st.opts.onPick(match, side);
        } else if (act === 'undo') {
            if (typeof st.opts.onClear === 'function') st.opts.onClear(match);
        }
    }

    function onContainerChange(container, e) {
        const sel = e.target;
        if (!sel || typeof sel.getAttribute !== 'function' || sel.getAttribute('data-cd-act') !== 'score') return;
        const st = renderStates.get(container);
        const current = sel.getAttribute('data-cd-cur') || '';
        const match = st ? findMatch(st.draw, toNum(sel.getAttribute('data-cd-mid'))) : null;
        if (!match || container.classList.contains('cd-busy') || typeof st.opts.onScore !== 'function') {
            sel.value = current;
            return;
        }
        const legs = /^(\d+)-(\d+)$/.exec(sel.value || '');
        if (legs) st.opts.onScore(match, Number(legs[1]), Number(legs[2]));
        else st.opts.onScore(match, null, null);
    }

    function bindContainer(container) {
        if (boundContainers.has(container)) return;
        boundContainers.add(container);
        // Delegated once per container: render() replaces the content after every result
        container.addEventListener('click', e => onContainerClick(container, e));
        container.addEventListener('change', e => onContainerChange(container, e));
    }

    // Renders one draw into container (replacing its content). Safe to call again
    // and again: the same draw_id keeps its view, selected round and tree scroll.
    function render(container, draw, opts) {
        if (!container) return;
        opts = opts || {};
        bindContainer(container);
        // New data has arrived, so nothing is pending any more
        container.classList.remove('cd-busy');
        container.removeAttribute('aria-busy');
        if (!draw || typeof draw !== 'object') {
            renderStates.delete(container);
            container.innerHTML = '<p class="cd-empty">No draw to show.</p>';
            return;
        }
        const prev = renderStates.get(container);
        const drawId = toNum(draw.draw_id);
        const same = !!prev && drawId !== null && prev.drawId === drawId;
        if (same) captureUi(container, prev);
        const st = {
            drawId: drawId,
            draw: draw,
            opts: opts,
            model: buildModel(draw),
            view: same ? prev.view : null,
            viewSource: same ? prev.viewSource : null,
            round: same ? prev.round : null,
            scroll: same ? prev.scroll : {},
            focusKey: same ? prev.focusKey : null
        };
        // Keep the viewer's view, unless it was only a guess or the caller now asks for a different one
        const asked = opts.view === 'bracket' || opts.view === 'rounds' ? opts.view : null;
        const askedBefore = same && (prev.opts.view === 'bracket' || prev.opts.view === 'rounds') ? prev.opts.view : null;
        if (!st.view || st.viewSource === 'guess' || (asked && asked !== askedBefore)) {
            const choice = chooseView(container, opts.view);
            st.view = choice.view;
            st.viewSource = choice.source;
        }
        if (!st.round || !st.model.roundByKey.has(st.round)) st.round = st.model.defaultRound ? st.model.defaultRound.key : null;
        renderStates.set(container, st);
        paint(container, st);
    }

    function setBusy(container, isBusy) {
        if (!container || !container.classList) return;
        if (isBusy) {
            container.classList.add('cd-busy');
            container.setAttribute('aria-busy', 'true');
            return;
        }
        container.classList.remove('cd-busy');
        container.removeAttribute('aria-busy');
        // A failed save leaves the old draw on screen: put any leg score select back to the stored score
        Array.prototype.forEach.call(container.querySelectorAll('select[data-cd-act="score"]'), sel => {
            const current = sel.getAttribute('data-cd-cur') || '';
            if (sel.value !== current) sel.value = current;
        });
    }

    // ---------- public page ----------

    function hashDivision() {
        let h = '';
        try {
            h = decodeURIComponent(String(window.location.hash || '').replace(/^#/, ''));
        } catch (e) {
            h = '';
        }
        return h;
    }

    function showPageMessage(page, text, isError) {
        page.wrap = null;
        page.tabs = null;
        page.keys = '';
        page.sections = new Map();
        page.active = null;
        page.root.innerHTML = `<p class="cd-empty${isError ? ' cd-error' : ''}"${isError ? ' role="alert"' : ''}>${esc(text)}</p>`;
    }

    function makeSection(key) {
        const el = document.createElement('section');
        el.className = 'cd-section';
        const heading = document.createElement('h3');
        heading.className = 'cd-divhead';
        heading.textContent = divisionLabel(key);
        const meta = document.createElement('p');
        meta.className = 'cd-meta';
        const box = document.createElement('div');
        box.className = 'cd-drawbox';
        el.appendChild(heading);
        el.appendChild(meta);
        el.appendChild(box);
        return { key: key, el: el, meta: meta, box: box, draw: null, shownJson: null };
    }

    function makeTab(key) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cd-tab';
        btn.setAttribute('data-cd-tab', key);
        btn.setAttribute('aria-pressed', 'false');
        btn.textContent = divisionLabel(key);
        return btn;
    }

    // Puts els into parent in this order. An element already in parent is left exactly
    // where it is: moving it, even for a moment, takes it out of the page, and that resets
    // the sideways scroll of a bracket inside it and loses the focus. Divisions always
    // keep the same order, so only the new elements need placing.
    function placeInOrder(parent, els) {
        let after = null;
        for (let i = els.length - 1; i >= 0; i--) {
            if (els[i].parentNode !== parent) parent.insertBefore(els[i], after);
            after = els[i];
        }
    }

    // Brings the tabs and sections in line with the divisions that have a draw. Only the
    // divisions that come or go are added or removed: the rest stay in the page untouched,
    // so the draw being viewed keeps its view, round, bracket scroll and focus.
    function buildPage(page, list) {
        const keys = list.map(item => item.key);
        // New sections are made first: if that fails, the page is left as it was
        const sections = new Map();
        keys.forEach(key => sections.set(key, page.sections.get(key) || makeSection(key)));
        if (!page.wrap || page.wrap.parentNode !== page.root) {
            page.wrap = document.createElement('div');
            page.wrap.className = 'cd-page';
            page.tabs = null;
            page.root.innerHTML = '';
            page.root.appendChild(page.wrap);
        }
        // Division tabs only when there is more than one draw
        if (keys.length > 1) {
            if (!page.tabs) {
                page.tabs = document.createElement('div');
                page.tabs.className = 'cd-tabs';
                page.tabs.setAttribute('role', 'group');
                page.tabs.setAttribute('aria-label', 'Choose a division');
                page.wrap.insertBefore(page.tabs, page.wrap.firstChild);
            }
            const oldTabs = Array.prototype.slice.call(page.tabs.children);
            oldTabs.forEach(btn => {
                if (keys.indexOf(btn.getAttribute('data-cd-tab')) === -1) page.tabs.removeChild(btn);
            });
            placeInOrder(page.tabs, keys.map(key => oldTabs.find(btn => btn.getAttribute('data-cd-tab') === key) || makeTab(key)));
        } else if (page.tabs) {
            page.wrap.removeChild(page.tabs);
            page.tabs = null;
        }
        page.sections.forEach((sec, key) => {
            if (!sections.has(key) && sec.el.parentNode) sec.el.parentNode.removeChild(sec.el);
        });
        placeInOrder(page.wrap, keys.map(key => sections.get(key).el));
        page.sections = sections;
        page.keys = keys.join(',');
    }

    // Draws are rendered when their tab is shown: a hidden container cannot be
    // measured for view 'auto', and there is no point drawing what nobody sees
    function showSection(page, key) {
        page.active = key;
        page.sections.forEach((sec, k) => { sec.el.hidden = k !== key; });
        if (page.tabs) {
            Array.prototype.forEach.call(page.tabs.children, btn => {
                const on = btn.getAttribute('data-cd-tab') === key;
                btn.classList.toggle('cd-on', on);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        const sec = page.sections.get(key);
        if (!sec || !sec.draw) return;
        const json = JSON.stringify(sec.draw);
        if (json === sec.shownJson) return;
        sec.shownJson = json;
        sec.meta.textContent = formatLine(sec.draw);
        render(sec.box, sec.draw, { admin: false, view: 'auto' });
    }

    function applyDraws(page, draws) {
        const list = [];
        DIVISIONS.forEach(d => {
            const draw = draws.find(x => x.division === d.key);
            if (draw) list.push({ key: d.key, draw: draw });
        });
        if (!list.length) {
            showPageMessage(page, MSG_EMPTY, false);
            return;
        }
        const keys = list.map(item => item.key).join(',');
        if (!page.wrap || page.wrap.parentNode !== page.root || keys !== page.keys) buildPage(page, list);
        list.forEach(item => { page.sections.get(item.key).draw = item.draw; });
        // Keep the tab the viewer is on; on first load take #premier / #a / #combined
        let active = page.active && page.sections.has(page.active) ? page.active : null;
        if (!active) {
            const wanted = hashDivision();
            if (wanted && page.sections.has(wanted)) active = wanted;
        }
        showSection(page, active || list[0].key);
    }

    async function loadPage(page, first) {
        if (page.loading || page.stopped) return;
        page.loading = true;
        let res;
        try {
            res = await fetchDraws(page.cup);
        } finally {
            page.loading = false;
        }
        if (page.stopped) return;
        page.lastFetch = Date.now();
        if (!res || res.success !== true) {
            // A failed refresh keeps the draw that is already on screen
            if (first) showPageMessage(page, MSG_FAILED, true);
            return;
        }
        const draws = res.schema_ready === false || !Array.isArray(res.draws) ? [] :
            res.draws.filter(d => d && typeof d === 'object' && (!page.cup || !d.cup_slug || d.cup_slug === page.cup));
        const json = JSON.stringify(draws);
        if (!first && json === page.json) return;
        page.json = json;
        page.draws = draws;
        if (!draws.length) {
            showPageMessage(page, MSG_EMPTY, false);
            return;
        }
        try {
            applyDraws(page, draws);
        } catch (e) {
            // Never leave "Loading the draw..." on screen; a failed refresh keeps what is shown
            if (window.console) console.error('Cup draw could not be shown', e);
            if (first) showPageMessage(page, MSG_FAILED, true);
        }
    }

    function pageNeedsRefresh(page) {
        return page.draws.some(d => !isOn(d.complete));
    }

    function onRootClick(root, e) {
        const page = pageStates.get(root);
        const tab = e.target && typeof e.target.closest === 'function' ? e.target.closest('[data-cd-tab]') : null;
        if (!page || !tab || !root.contains(tab)) return;
        const key = tab.getAttribute('data-cd-tab');
        if (!page.sections.has(key) || key === page.active) return;
        showSection(page, key);
        try {
            window.history.replaceState(window.history.state, '', '#' + key);
        } catch (err) {
            // Some embedded browsers refuse replaceState; the tab still switches
        }
    }

    // Public cup page: loads this cup's draws and keeps them up to date
    function mountPage(rootEl, cupSlug) {
        if (!rootEl) return Promise.resolve();
        const old = pageStates.get(rootEl);
        if (old) old.stop();

        const page = {
            root: rootEl, cup: cupSlug || '', wrap: null, tabs: null, keys: '',
            sections: new Map(), active: null, json: null, draws: [],
            loading: false, stopped: false, lastFetch: 0, timer: null, stop: null
        };
        const onHash = () => {
            // The nav's dropdown toggles use href="#", so an empty hash means nothing
            const wanted = hashDivision();
            if (wanted && wanted !== page.active && page.sections.has(wanted)) showSection(page, wanted);
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible' && pageNeedsRefresh(page) && Date.now() - page.lastFetch >= REFRESH_MS) {
                loadPage(page, false);
            }
        };
        page.timer = setInterval(() => {
            if (document.visibilityState === 'visible' && pageNeedsRefresh(page)) loadPage(page, false);
        }, REFRESH_MS);
        window.addEventListener('hashchange', onHash);
        document.addEventListener('visibilitychange', onVisible);
        page.stop = () => {
            page.stopped = true;
            clearInterval(page.timer);
            window.removeEventListener('hashchange', onHash);
            document.removeEventListener('visibilitychange', onVisible);
        };
        pageStates.set(rootEl, page);
        if (!boundRoots.has(rootEl)) {
            boundRoots.add(rootEl);
            rootEl.addEventListener('click', e => onRootClick(rootEl, e));
        }

        rootEl.innerHTML = `<p class="cd-status" role="status">${MSG_LOADING}</p>`;
        return loadPage(page, true);
    }

    window.CupDraw = {
        CUPS: CUPS,
        DIVISIONS: DIVISIONS,
        fetchDraws: fetchDraws,
        divisionLabel: divisionLabel,
        formatLine: formatLine,
        render: render,
        setBusy: setBusy,
        mountPage: mountPage
    };
})();
