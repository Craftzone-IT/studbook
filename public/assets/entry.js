// Fast entry: part → colour → quantity, fully usable with the keyboard.
// Without this script the page still works through plain links.
(() => {
    const root = document.getElementById('entry');
    if (!root) {
        return;
    }
    const $ = (id) => document.getElementById(id);
    const partInput = $('entry-part');
    const partList = $('entry-part-list');
    const colorInput = $('entry-color');
    const colorList = $('entry-color-list');
    const qtyInput = $('entry-qty');
    const submit = $('entry-submit');
    const selected = $('entry-selected');
    const hint = $('entry-hint');
    const status = $('entry-status');
    const undoForm = $('entry-undo');
    const summary = $('entry-session-summary');
    const contents = $('entry-contents');
    const t = (name) => root.dataset['t' + name] || '';

    const labelItems = partList.innerHTML;
    let part = null;
    let colors = [];
    let color = null;
    let colorSynonyms = {};
    let wantedColor = null;
    let searchTimer = null;
    let searchSeq = 0;
    let searchDone = Promise.resolve();

    const img = (rb, colorId) => `${root.dataset.imgUrl}?part=${encodeURIComponent(rb)}&color=${colorId}`;
    const el = (tag, attrs = {}, children = []) => {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'text') {
                node.textContent = value;
            } else {
                node.setAttribute(key, value);
            }
        }
        children.forEach((child) => node.append(child));
        return node;
    };
    const swatch = (rgb) => {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'swatch');
        svg.setAttribute('viewBox', '0 0 10 10');
        svg.setAttribute('width', '14');
        svg.setAttribute('height', '14');
        const rect = document.createElementNS(ns, 'rect');
        rect.setAttribute('width', '10');
        rect.setAttribute('height', '10');
        rect.setAttribute('rx', '2');
        rect.setAttribute('fill', /^[0-9a-f]{6}$/i.test(rgb) ? `#${rgb}` : '#ccc');
        rect.setAttribute('stroke', 'rgba(0,0,0,.35)');
        svg.append(rect);
        return svg;
    };

    // Keyboard navigation inside a list of options.
    const items = (list) => Array.from(list.querySelectorAll('[data-part], [data-color]'));
    const active = (list) => list.querySelector('.is-active');
    const setActive = (list, item) => {
        items(list).forEach((i) => i.classList.toggle('is-active', i === item));
        if (item) {
            item.scrollIntoView({ block: 'nearest' });
        }
    };
    const move = (list, delta) => {
        const all = items(list).filter((i) => !i.closest('li').hidden);
        if (all.length === 0) {
            return;
        }
        const index = all.indexOf(active(list));
        setActive(list, all[Math.max(0, Math.min(all.length - 1, index + delta))] || all[0]);
    };
    const firstVisible = (list) => items(list).find((i) => !i.closest('li').hidden) || null;

    // Step 1: part.
    const renderParts = (parts, corrections) => {
        partList.innerHTML = '';
        if (parts.length === 0) {
            partList.append(el('li', { class: 'pick-empty', text: t('NoResults') }));
            return;
        }
        parts.forEach((p) => {
            partList.append(el('li', {}, [el('a', { class: 'pick-item', href: '#', 'data-part': p.rb_num, role: 'option' }, [
                el('img', { class: 'part-img', src: img(p.rb_num, -1), alt: '', width: '40', height: '40', loading: 'lazy' }),
                el('strong', { text: p.display }),
                el('span', { class: 'pick-name', text: p.name }),
            ])]));
        });
        const fixed = Object.entries(corrections || {}).map(([from, to]) => `${from} → ${to}`).join(', ');
        if (fixed) {
            partList.prepend(el('li', { class: 'pick-note', text: `${t('Corrected')} ${fixed}` }));
        }
        setActive(partList, firstVisible(partList));
    };
    const search = () => {
        clearTimeout(searchTimer);
        searchTimer = null;
        const q = partInput.value.trim();
        const seq = ++searchSeq;
        if (q === '') {
            partList.innerHTML = labelItems;
            wantedColor = null;
            setActive(partList, null);
            searchDone = Promise.resolve();
            return searchDone;
        }
        searchDone = fetch(`${root.dataset.searchUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => {
                if (seq !== searchSeq) {
                    return;
                }
                wantedColor = data.color ? data.color.id : null;
                renderParts(data.parts || [], data.corrections);
            })
            .catch(() => { status.textContent = t('Error'); });
        return searchDone;
    };
    const choosePart = (rb) => {
        fetch(`${root.dataset.partUrl}?part=${encodeURIComponent(rb)}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    status.textContent = data.error || t('Error');
                    return;
                }
                part = data.part;
                colors = data.colors || [];
                colorSynonyms = data.colorSynonyms || colorSynonyms;
                selected.textContent = '';
                selected.append(
                    el('img', { class: 'part-img', src: img(part.rb_num, -1), alt: '', width: '40', height: '40' }),
                    el('strong', { text: part.display }),
                    document.createTextNode(' ' + part.name),
                );
                if (data.hint && data.hint.length > 0) {
                    hint.textContent = t('Hint') + ' ';
                    data.hint.forEach((b, i) => {
                        hint.append(el('a', { href: root.dataset.boxUrl + b.id + '/entry', text: b.name }));
                        if (i < data.hint.length - 1) {
                            hint.append(document.createTextNode(', '));
                        }
                    });
                    hint.hidden = false;
                } else {
                    hint.hidden = true;
                }
                renderColors();
                colorInput.disabled = false;
                colorInput.value = '';
                if (wantedColor !== null && colors.some((c) => c.id === wantedColor)) {
                    chooseColor(wantedColor);
                } else {
                    color = null;
                    updateSubmit();
                    colorInput.focus();
                }
            })
            .catch(() => { status.textContent = t('Error'); });
    };

    // Step 2: colour.
    const renderColors = () => {
        colorList.innerHTML = '';
        if (colors.length === 0) {
            colorList.append(el('li', { class: 'pick-empty', text: t('NoColors') }));
            return;
        }
        colors.forEach((c) => {
            colorList.append(el('li', {}, [el('a', { class: 'pick-item', href: '#', 'data-color': String(c.id), role: 'option' }, [
                el('img', { class: 'part-img', src: img(c.part || part.rb_num, c.id), alt: '', width: '40', height: '40', loading: 'lazy' }),
                swatch(c.rgb),
                el('span', { text: c.name }),
            ])]));
        });
        filterColors();
    };
    const normalise = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const filterColors = () => {
        const words = colorInput.value.toLowerCase().split(/\s+/).filter(Boolean)
            .map((w) => colorSynonyms[w] || w).join(' ').split(' ').map(normalise);
        items(colorList).forEach((item) => {
            const name = normalise(item.textContent);
            item.closest('li').hidden = !words.every((w) => name.includes(w));
        });
        setActive(colorList, firstVisible(colorList));
    };
    const chooseColor = (id) => {
        color = colors.find((c) => c.id === Number(id)) || null;
        items(colorList).forEach((i) => i.classList.toggle('is-selected', Number(i.dataset.color) === Number(id)));
        updateSubmit();
        if (color) {
            qtyInput.focus();
            qtyInput.select();
        }
    };

    // Step 3: quantity.
    const updateSubmit = () => {
        qtyInput.disabled = !color;
        submit.disabled = !color;
    };
    const add = () => {
        if (!part || !color) {
            return;
        }
        const body = new FormData();
        body.append('_csrf', root.dataset.csrf);
        body.append('part', part.rb_num);
        body.append('color', String(color.id));
        body.append('qty', qtyInput.value || '1');
        submit.disabled = true;
        fetch(root.dataset.addUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                status.textContent = ok ? data.message : (data.error || t('Error'));
                status.classList.toggle('is-error', !ok);
                if (!ok) {
                    return;
                }
                contents.innerHTML = data.contents;
                summary.textContent = data.session.summary;
                undoForm.action = undoForm.dataset.actionBase + data.session.batch + '/undo';
                undoForm.hidden = false;
                // The part stays selected; continue with the next colour.
                qtyInput.value = '1';
                colorInput.value = '';
                filterColors();
                colorInput.focus();
            })
            .catch(() => { status.textContent = t('Error'); })
            .finally(updateSubmit);
    };

    // Events.
    partInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(search, 180);
    });
    partInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            move(partList, e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // A fast typist can press Enter before the results for the full query have arrived.
            const ready = searchTimer !== null ? search() : searchDone;
            ready.then(() => {
                const item = active(partList) || firstVisible(partList);
                if (item) {
                    choosePart(item.dataset.part);
                }
            });
        }
    });
    partList.addEventListener('click', (e) => {
        const item = e.target.closest('[data-part]');
        if (item) {
            e.preventDefault();
            wantedColor = null;
            choosePart(item.dataset.part);
        }
    });
    colorInput.addEventListener('input', filterColors);
    colorInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            move(colorList, e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const item = active(colorList) || firstVisible(colorList);
            if (item) {
                chooseColor(item.dataset.color);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            partInput.focus();
            partInput.select();
        }
    });
    colorList.addEventListener('click', (e) => {
        const item = e.target.closest('[data-color]');
        if (item) {
            e.preventDefault();
            chooseColor(item.dataset.color);
        }
    });
    qtyInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            colorInput.focus();
            colorInput.select();
        }
    });
    root.querySelectorAll('.qty-step').forEach((button) => {
        button.addEventListener('click', () => {
            const value = (parseInt(qtyInput.value, 10) || 1) + Number(button.dataset.step);
            qtyInput.value = String(Math.max(1, value));
        });
    });
    $('entry-form').addEventListener('submit', (e) => {
        e.preventDefault();
        add();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            partInput.focus();
        }
    });

    if (root.dataset.preselect) {
        choosePart(root.dataset.preselect);
    } else {
        partInput.focus();
    }
})();
