<script>
(() => {
    const $ = (selector) => document.querySelector(selector);
    const h = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const icon = (name, className = '') => `<svg class="icon ${className}" aria-hidden="true"><use href="#qbt-${name}"/></svg>`;
    const empty = (text, name = 'folder') => `<div class="empty">${icon(name)}<span>${h(text)}</span></div>`;
    const labels = { imported: '已入库', referenced: '已引用', skipped: '相同跳过' };
    let csrf = $('meta[name=csrf-token]').content;
    let path = '', folders = [], files = [], selected = new Set(), work = null;
    let running = false, stopping = false, loading = false, page = 1, workPage = null, workVersion = 0;
    let saved = Object.create(null), originalOrder = [], customOrder = false;
    const collator = new Intl.Collator('zh-CN', { numeric: true, sensitivity: 'base' });
    const fileName = (file) => file.path.split('/').at(-1);
    const savedTime = (value) => new Date(value).toLocaleString('zh-CN', { hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });

    function message(text, error = false) { $('#message').textContent = text; $('#message').hidden = !text; $('#message').className = error ? 'error' : ''; }
    async function api(url, method = 'GET', body, retry = true) {
        const response = await fetch(`/qbt/${url}`, { method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }) }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        if (response.redirected || response.status === 401) { running = false; location.assign('/'); throw new Error('登录已失效。'); }
        if (response.status === 419 && retry) {
            const token = await fetch('/api/csrf-token', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!token.ok) throw new Error('会话已失效，请重新登录。');
            csrf = (await token.json()).csrf_token;
            return api(url, method, body, false);
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { const error = new Error(data.error || data.message || `请求失败（${response.status}）`); error.status = response.status; throw error; }
        return data;
    }
    const guard = async (task) => {
        try { await task(); } catch (error) { message(error.message, true); }
    };
    function currentFile(text) {
        Object.assign($('#currentFile'), { textContent: text, title: text, hidden: !text });
    }
    function statusText(processed, failed) {
        if (loading) return '正在读取目录';
        if (running) return stopping ? '正在暂停' : '正在入库';
        if (failed) return '有文件需要重试';
        if (files.length && processed === files.length) return '全部已处理';
        return work ? '准备就绪' : '等待选择作品';
    }
    function renderControls() {
        let processed = 0, pending = 0, failed = 0;
        for (const file of files) {
            if (file.result) processed++;
            else if (selected.has(file.path)) pending++;
            if (file.error) failed++;
        }
        $('#importPanel').hidden = path === '';
        $('#welcome').hidden = path !== '';
        for (const id of ['reload', 'root', 'parent', 'chooseWork', 'season', 'episode', 'duplicates', 'selectAll', 'selectNone', 'fileSort', 'reverseOrder', 'resetOrder', 'movePosition', 'moveSelected']) $(`#${id}`).disabled = running || loading;
        $('#parent').disabled ||= path === '';
        $('#start').disabled = running || loading || !work || pending === 0;
        $('#pause').hidden = !running; $('#pause').disabled = stopping;
        $('#pauseLabel').textContent = stopping ? '正在暂停…' : '暂停';
        $('#retry').hidden = failed === 0; $('#retry').disabled = running || loading;
        $('#summary').innerHTML = `<span>已处理<b>${processed.toLocaleString()}</b></span><span>待处理<b>${pending.toLocaleString()}</b></span>${failed ? `<span class="has-errors">失败<b>${failed.toLocaleString()}</b></span>` : ''}`;
        $('#fileCount').textContent = files.length.toLocaleString();
        $('#selectionCount').textContent = pending ? `已选 ${pending.toLocaleString()} 个待处理文件` : '';
        $('#importStatus').textContent = statusText(processed, failed);
        $('.import-status').classList.toggle('is-running', running);
        $('#importProgress').max = files.length || 1; $('#importProgress').value = processed;
        $('#moveSelected').disabled ||= selected.size === 0;
        $('#movePosition').max = Math.max(1, files.length - Math.max(1, selected.size) + 1);
        $('#directoryMarker').hidden = !path;
        $('#directoryState').classList.toggle('saved', !!saved[path]);
        $('#directoryState').textContent = saved[path] ? '已保存过' : '未保存';
        $('#directoryState').title = '目录或子目录的保存记录；具体处理进度见文件列表';
        $('#directorySavedAt').textContent = saved[path] ? `首次 ${savedTime(saved[path])}` : '';
    }
    function renderWork() {
        $('#chosenWorkName').textContent = work?.name || '选择已有作品';
        $('#chosenWorkMeta').textContent = work ? [`#${work.id}`, work.date_air?.slice(0, 4) || '年份未填', work.library?.name].filter(Boolean).join(' · ') : '搜索作品名称或 ID';
        $('#chooseWork').title = work ? `#${work.id} ${work.name}` : '选择已有作品';
        $('#chooseWork').classList.toggle('has-work', !!work);
        const cover = work?.cover_url || '';
        $('#chosenWorkCover').hidden = !cover; $('#chosenWorkPlaceholder').hidden = !!cover;
        if (cover && $('#chosenWorkCover').getAttribute('src') !== cover) $('#chosenWorkCover').src = cover;
    }
    function render() {
        renderControls();
        renderWork();
        renderFolders();
        renderFiles();
    }
    function renderFolders() {
        $('.folder-panel').setAttribute('aria-busy', String(loading));
        $('.folder-panel').classList.toggle('is-leaf', path !== '' && folders.length === 0 && !loading);
        const query = $('#folderSearch').value.toLocaleLowerCase();
        const state = $('#folderState').value;
        const matches = folders.filter((name) => name.toLocaleLowerCase().includes(query) && (state === 'all' || !!saved[[path, name].filter(Boolean).join('/')] === (state === 'saved')));
        $('#folderCount').textContent = matches.length.toLocaleString();
        $('#folders').innerHTML = loading ? empty('正在读取目录…') : matches.map((name) => {
            const time = saved[[path, name].filter(Boolean).join('/')];
            return `<button class="folder" data-folder="${h(name)}" title="${h(name)}" ${running ? 'disabled' : ''}><span class="folder-icon">${icon('folder')}</span><span class="folder-info"><span class="folder-name">${h(name)}</span><span class="folder-meta"><span class="saved-badge ${time ? 'saved' : ''}">${time ? `${icon('check')}已保存过` : '未保存'}</span>${time ? `<time title="首次保存时间">${h(savedTime(time))}</time>` : ''}</span></span>${icon('chevron', 'folder-arrow')}</button>`;
        }).join('') || empty(query || state !== 'all' ? '没有匹配的目录' : path ? '这个目录没有子目录' : '还没有资源目录');
    }
    async function open(nextPath) {
        if (running || loading) return;
        if (customOrder && files.some((file) => !file.result) && !confirm('离开当前目录会丢弃尚未用于入库的排序，继续吗？')) return;
        loading = true; message(''); render();
        try {
            const [directory, listing] = await Promise.all([
                api(`directories?${new URLSearchParams({ path: nextPath })}`),
                nextPath ? api(`files?${new URLSearchParams({ path: nextPath })}`) : Promise.resolve({ items: [] }),
            ]);
            path = nextPath; folders = directory.items; files = listing.items;
            saved = Object.assign(Object.create(null), directory.saved || {});
            originalOrder = [...files]; customOrder = false; $('#fileSort').value = 'original';
            selected = new Set(files.map((file) => file.path)); work = null; page = 1;
            $('#currentPath').textContent = path || '尚未选择';
            $('#folderSearch').value = ''; $('#fileSearch').value = ''; currentFile('');
        } finally { loading = false; render(); }
    }
    function visibleFiles() { const query = $('#fileSearch').value.toLocaleLowerCase(); return files.filter((file) => file.path.toLocaleLowerCase().includes(query)); }
    function pager(kind, current, pages, suffix = '') {
        return `<button class="secondary small" data-${kind}-page="${current - 1}" ${current <= 1 ? 'disabled' : ''}>上一页</button><span>${current} / ${pages}${suffix}</span><button class="secondary small" data-${kind}-page="${current + 1}" ${current >= pages ? 'disabled' : ''}>下一页</button>`;
    }
    function renderFiles() {
        const filtered = visibleFiles(), pages = Math.max(1, Math.ceil(filtered.length / 50));
        const positions = new Map(files.map((file, index) => [file.path, index]));
        page = Math.min(page, pages);
        $('#files').innerHTML = filtered.slice((page - 1) * 50, page * 50).map((file) => {
            const relative = file.path.slice(path.length + 1), slash = relative.lastIndexOf('/');
            const name = relative.slice(slash + 1), group = slash < 0 ? '' : relative.slice(0, slash);
            const status = file.result?.status || (file.error ? 'failed' : 'pending');
            const position = positions.get(file.path), locked = running || loading;
            return `<div class="file"><input type="checkbox" data-file="${h(file.path)}" aria-label="选择 ${h(file.path)}" ${selected.has(file.path) ? 'checked' : ''} ${locked || file.result ? 'disabled' : ''}><span class="audio-icon">${icon('audio')}</span><div class="file-info"><div class="filename" title="${h(relative)}">${h(name)}</div>${group || file.result ? `<div class="file-meta">${group ? `<span class="file-group" title="${h(group)}">${h(group)}</span>` : ''}${file.result ? `<a href="/#/works/${file.result.work_id}" target="_blank" rel="noopener">作品 #${file.result.work_id}</a>${file.result.episode_number ? `<span>S${file.result.season_number}E${file.result.episode_number}</span>` : ''}` : ''}</div>` : ''}${file.error ? `<p class="file-error">${h(file.error)}</p>` : ''}</div><span class="file-status ${h(status)}">${status === 'imported' ? icon('check') : ''}${h(labels[status] || (status === 'failed' ? '失败' : '待入库'))}</span><div class="file-order"><button class="quiet" data-move-file="${h(file.path)}" data-step="-1" ${locked || position === 0 ? 'disabled' : ''} aria-label="上移 ${h(name)}">↑</button><input type="number" min="1" max="${files.length}" value="${position + 1}" data-position="${h(file.path)}" ${locked ? 'disabled' : ''} aria-label="${h(name)} 的位置"><button class="quiet" data-move-file="${h(file.path)}" data-step="1" ${locked || position === files.length - 1 ? 'disabled' : ''} aria-label="下移 ${h(name)}">↓</button></div></div>`;
        }).join('') || empty($('#fileSearch').value ? '没有匹配的音频' : '这个目录没有支持的音频文件', 'audio');
        $('#filePager').innerHTML = pager('file', page, pages, ` · ${filtered.length} 个音频`);
        $('#filePager').hidden = pages <= 1;
    }
    function episodeKey(file) {
        const name = fileName(file).normalize('NFKC');
        const season = name.match(/s(\d+)\s*e(\d+)/i);
        if (season) return [Number(season[1]), Number(season[2])];
        const episode = name.match(/第\s*(\d+)\s*[集章回话期]/) || name.match(/^(\d+)/);
        return episode ? [0, Number(episode[1])] : [Infinity, Infinity];
    }
    function sortFiles(mode) {
        if (running || loading) return;
        if (mode === 'original') files = [...originalOrder];
        else files.sort((a, b) => {
            if (mode === 'episode') {
                const left = episodeKey(a), right = episodeKey(b);
                const difference = left[0] - right[0] || left[1] - right[1];
                if (difference) return difference;
            }
            return collator.compare(fileName(a), fileName(b)) || collator.compare(a.path, b.path);
        });
        customOrder = mode !== 'original'; page = 1; renderFiles();
    }
    function moveFiles(paths, destination) {
        if (running || loading) return;
        const picked = new Set(paths), moved = files.filter((file) => picked.has(file.path));
        if (!moved.length) throw new Error('请先选择要移动的文件。');
        const remaining = files.filter((file) => !picked.has(file.path));
        const position = Number(destination);
        if (!Number.isInteger(position) || position < 1 || position > remaining.length + 1) {
            renderFiles();
            throw new Error(`最终起始位置须在 1 到 ${remaining.length + 1} 之间。`);
        }
        files = [...remaining.slice(0, position - 1), ...moved, ...remaining.slice(position - 1)];
        customOrder = true; $('#fileSort').value = 'custom'; page = Math.floor((position - 1) / 50) + 1; renderFiles();
    }
    async function loadWorks(nextPage = 1) {
        const version = ++workVersion;
        const result = await api(`works?${new URLSearchParams({ page: nextPage, q: $('#workSearch').value })}`);
        if (version !== workVersion) return;
        workPage = result;
        $('#workResults').innerHTML = result.items.map((item, index) => `<button class="work ${work?.id === item.id ? 'is-selected' : ''}" data-work="${index}">${item.cover_url ? `<img src="${h(item.cover_url)}" alt="" loading="lazy">` : `<span class="work-cover">${icon('book')}</span>`}<div class="work-info"><strong class="work-title">${h(item.name)}</strong>${item.tagline ? `<span class="work-subtitle">${h(item.tagline)}</span>` : ''}<span class="work-meta"><span>#${item.id}</span><span>${h(item.date_air?.slice(0, 4) || '年份未填')}</span><span>${h(item.library?.name || '')}</span></span></div>${icon(work?.id === item.id ? 'check' : 'chevron', 'work-arrow')}</button>`).join('') || empty('没有匹配作品，请在首页创建；成人作品受账号成人模式设置影响。', 'book');
        $('#workPager').innerHTML = pager('work', result.page, Math.max(1, Math.ceil(result.total / result.page_size)));
    }
    async function start(retry = false) {
        if (running || loading) return;
        if (!work) throw new Error('请先选择已有作品。');
        const season = Number($('#season').value), first = $('#episode').value === '' ? null : Number($('#episode').value);
        if (!Number.isInteger(season) || season < 1 || season > 100000 || (first !== null && (!Number.isInteger(first) || first < 1 || first > 1000000))) throw new Error('请检查季和起始集数。');
        const tasks = files.filter((file) => selected.has(file.path)).map((file, index) => ({ file, episode: first === null ? null : first + index })).filter(({ file }) => !file.result && (!retry || file.error));
        if (!tasks.length) throw new Error('没有选中待处理文件。');
        if (tasks.some((task) => task.episode > 1000000)) throw new Error('集数超出范围。');
        const target = { work_id: work.id, season, duplicates: $('#duplicates').value };
        running = true; stopping = false; message(''); render();
        try {
            for (const { file, episode } of tasks) {
                if (stopping) break;
                currentFile(`正在处理：${file.path.slice(path.length + 1)}`);
                try {
                    const result = await api('import', 'POST', { ...target, path: file.path, episode });
                    file.result = result.data; file.error = null;
                    const changed = Object.keys(result.saved || {}).some((directory) => !saved[directory]);
                    Object.assign(saved, result.saved || {});
                    if (changed) renderFolders();
                } catch (error) {
                    file.error = error.message;
                    if (!error.status || [401, 403, 404, 419].includes(error.status) || error.status >= 500) { stopping = true; message(error.message, true); }
                }
                renderControls(); renderFiles();
            }
            currentFile(stopping ? '已暂停，可继续处理未完成的文件。' : '本次处理完成。');
        } finally { running = false; stopping = false; render(); }
    }

    const actions = {
        reload: () => open(path),
        root: () => open(''),
        parent: () => open(path.split('/').slice(0, -1).join('/')),
        start: () => start(),
        retry: () => start(true),
        pause: () => { stopping = true; renderControls(); },
        selectAll: () => { visibleFiles().forEach((file) => selected.add(file.path)); render(); },
        selectNone: () => { selected.clear(); render(); },
        reverseOrder: () => { files.reverse(); customOrder = true; $('#fileSort').value = 'custom'; page = 1; renderFiles(); },
        resetOrder: () => { $('#fileSort').value = 'original'; sortFiles('original'); },
        moveSelected: () => moveFiles([...selected], $('#movePosition').value),
        chooseWork: () => {
            $('#workResults').innerHTML = empty('正在读取作品…', 'book');
            $('#workDialog').showModal(); $('#workSearch').focus();
            return loadWorks();
        },
        closeDialog: () => $('#workDialog').close(),
    };
    $('#folderSearch').addEventListener('input', renderFolders);
    $('#folderState').addEventListener('change', renderFolders);
    $('#fileSearch').addEventListener('input', () => { page = 1; renderFiles(); });
    let searchTimer;
    $('#workSearch').addEventListener('input', () => { clearTimeout(searchTimer); workVersion++; $('#workResults').textContent = '正在搜索…'; searchTimer = setTimeout(() => guard(() => loadWorks()), 250); });
    document.addEventListener('change', (event) => {
        const checkbox = event.target;
        if (checkbox.id === 'fileSort') return guard(() => sortFiles(checkbox.value));
        if (checkbox.dataset.position !== undefined) return guard(() => moveFiles([checkbox.dataset.position], checkbox.value));
        if (checkbox.dataset.file === undefined) return;
        checkbox.checked ? selected.add(checkbox.dataset.file) : selected.delete(checkbox.dataset.file);
        renderControls();
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button || button.disabled) return;
        if (actions[button.id]) return guard(actions[button.id]);
        if (button.dataset.moveFile !== undefined) return guard(() => moveFiles([button.dataset.moveFile], files.findIndex((file) => file.path === button.dataset.moveFile) + 1 + Number(button.dataset.step)));
        if (button.dataset.folder !== undefined) guard(() => open([path, button.dataset.folder].filter(Boolean).join('/')));
        if (button.dataset.filePage) { page = Number(button.dataset.filePage); renderFiles(); }
        if (button.dataset.workPage) guard(() => loadWorks(Number(button.dataset.workPage)));
        if (button.dataset.work !== undefined) { work = workPage.items[Number(button.dataset.work)]; $('#workDialog').close(); renderWork(); renderControls(); }
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Enter' && event.target.dataset.position !== undefined) { event.preventDefault(); event.target.blur(); } });
    window.addEventListener('beforeunload', (event) => { if (running || (customOrder && files.some((file) => !file.result))) { event.preventDefault(); event.returnValue = ''; } });
    guard(() => open(''));
})();
</script>
