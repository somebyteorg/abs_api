<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>QBT 导入 · EMOS</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @include('qbt.styles')
</head>
<body>
<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="qbt-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></symbol>
    <symbol id="qbt-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></symbol>
    <symbol id="qbt-refresh" viewBox="0 0 24 24"><path d="M20 7v5h-5M4 17v-5h5M6.1 6.1a8.3 8.3 0 0 1 12.7 1.2L20 12M4 12l1.2 4.7a8.3 8.3 0 0 0 12.7 1.2"/></symbol>
    <symbol id="qbt-arrow" viewBox="0 0 24 24"><path d="m14 6-6 6 6 6M8 12h12"/></symbol>
    <symbol id="qbt-chevron" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
    <symbol id="qbt-home" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7M5 9v11h14V9M9 20v-7h6v7"/></symbol>
    <symbol id="qbt-book" viewBox="0 0 24 24"><path d="M12 6v15M12 6C9 3 5 3 2 4v15c4-1 7 0 10 2 3-2 6-3 10-2V4c-3-1-7-1-10 2Z"/></symbol>
    <symbol id="qbt-audio" viewBox="0 0 24 24"><path d="M8 18V5l12-2v13M8 9l12-2"/><ellipse cx="5" cy="18" rx="3" ry="3"/><ellipse cx="17" cy="16" rx="3" ry="3"/></symbol>
    <symbol id="qbt-upload" viewBox="0 0 24 24"><path d="M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5"/></symbol>
    <symbol id="qbt-pause" viewBox="0 0 24 24"><path d="M8 5v14M16 5v14"/></symbol>
    <symbol id="qbt-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
    <symbol id="qbt-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
</svg>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="/"><img src="/favicon.svg" width="30" height="30" alt=""><span>EMOS · 有声书</span></a>
        <div class="header-actions"><span class="account"><span class="account-dot" aria-hidden="true"></span>{{ $actor }}</span><a class="home-link" href="/"><svg class="icon" aria-hidden="true"><use href="#qbt-arrow"/></svg>返回首页</a></div>
    </div>
</header>
<main class="app">
    <div class="page-heading">
        <div><h1>QBT 导入</h1><p>选择资源目录，将音频关联到已有作品。</p></div>
        <div class="source-path" title="{{ $sourceRoot }}"><svg class="icon" aria-hidden="true"><use href="#qbt-folder"/></svg><span>{{ $sourceRoot }}</span></div>
    </div>
    <p id="message" role="status" hidden></p>
    <div class="layout">
        <aside class="panel folder-panel">
            <div class="panel-heading"><div class="section-title"><h2>资源目录</h2><span id="folderCount" class="count">0</span></div><button id="reload" class="icon-button" title="刷新目录" aria-label="刷新目录"><svg class="icon" aria-hidden="true"><use href="#qbt-refresh"/></svg></button></div>
            <div class="navigation"><button id="root" class="secondary small"><svg class="icon" aria-hidden="true"><use href="#qbt-home"/></svg>根目录</button><button id="parent" class="quiet small"><svg class="icon" aria-hidden="true"><use href="#qbt-arrow"/></svg>上一级</button></div>
            <div class="search-field"><svg class="icon" aria-hidden="true"><use href="#qbt-search"/></svg><input id="folderSearch" type="search" placeholder="筛选目录" aria-label="筛选目录" maxlength="100"></div>
            <label class="folder-state-filter">显示<select id="folderState" aria-label="筛选目录保存状态"><option value="all">全部目录</option><option value="new">未保存</option><option value="saved">已保存过</option></select></label>
            <div id="folders"></div>
        </aside>
        <section class="panel import-panel">
            <div class="directory-heading"><span class="heading-icon"><svg class="icon" aria-hidden="true"><use href="#qbt-folder"/></svg></span><div class="directory-title"><p class="eyebrow">当前目录</p><h2 id="currentPath">尚未选择</h2></div><div id="directoryMarker" class="directory-marker" hidden><span id="directoryState" class="saved-badge">未保存</span><small id="directorySavedAt"></small></div></div>
            <div id="welcome" class="welcome"><span class="welcome-icon"><svg class="icon" aria-hidden="true"><use href="#qbt-audio"/></svg></span><h3>从一个目录开始</h3><p>打开左侧目录，选择要关联的作品。</p><small>合集可进入子目录分别导入。</small></div>
            <div id="importPanel" hidden>
                <div class="settings">
                    <div class="work-field"><label for="chooseWork">关联作品</label><button id="chooseWork" class="work-picker"><span id="chosenWorkPlaceholder" class="cover-placeholder"><svg class="icon" aria-hidden="true"><use href="#qbt-book"/></svg></span><img id="chosenWorkCover" class="chosen-cover" alt="" hidden><span class="chosen-work"><strong id="chosenWorkName">选择已有作品</strong><small id="chosenWorkMeta">搜索作品名称或 ID</small></span><svg class="icon picker-arrow" aria-hidden="true"><use href="#qbt-chevron"/></svg></button></div>
                    <label>季<input id="season" type="number" min="1" max="100000" value="1"></label>
                    <label>起始集数<input id="episode" type="number" min="1" max="1000000" placeholder="自动接续"></label>
                    <label>相同音频<select id="duplicates"><option value="skip">跳过</option><option value="reference">引用已有文件</option></select></label>
                </div>
                <p class="field-hint">包含子目录音频，按文件名自然排序；起始集数留空时自动接续。</p>
                <div class="import-status">
                    <div class="status-heading"><span id="importStatus" class="status-label">准备就绪</span><div id="summary" class="summary"></div></div>
                    <progress id="importProgress" max="1" value="0" aria-label="目录处理进度"></progress>
                    <p id="currentFile" aria-live="polite" hidden></p>
                    <div class="actions"><button id="start"><svg class="icon" aria-hidden="true"><use href="#qbt-upload"/></svg>开始入库</button><button id="pause" class="secondary" hidden><svg class="icon" aria-hidden="true"><use href="#qbt-pause"/></svg><span id="pauseLabel">暂停</span></button><button id="retry" class="secondary" hidden><svg class="icon" aria-hidden="true"><use href="#qbt-refresh"/></svg>重试失败</button><small>入库期间请保持页面打开</small></div>
                </div>
                <div class="files-heading"><div class="section-title"><h3>音频文件</h3><span id="fileCount" class="count">0</span></div><span id="selectionCount" class="selection-count"></span></div>
                <div class="filters"><div class="search-field"><svg class="icon" aria-hidden="true"><use href="#qbt-search"/></svg><input id="fileSearch" type="search" placeholder="筛选文件名" aria-label="筛选文件名" maxlength="100"></div><div class="selection-actions"><button id="selectAll" class="quiet small">选中筛选结果</button><button id="selectNone" class="quiet small">清空选择</button></div></div>
                <div class="sort-toolbar"><label>排序<select id="fileSort" aria-label="文件排序"><option value="original">默认目录顺序</option><option value="name">按文件名</option><option value="episode">按季／集数</option><option value="custom" disabled>手动排序</option></select></label><button id="reverseOrder" class="secondary small">倒序</button><button id="resetOrder" class="quiet small">恢复默认</button><div class="bulk-move"><label for="movePosition">选中移至</label><input id="movePosition" type="number" min="1" value="1" aria-label="选中文件的最终起始位置"><button id="moveSelected" class="secondary small">移动</button></div></div>
                <p class="sort-hint">按当前顺序逐个入库。可上下移动、修改行号或批量移动选中项；已入库章节请在作品页整理。</p>
                <div class="file-table-heading"><span>文件名</span><span>状态</span><span class="order-heading">顺序</span></div>
                <div id="files"></div><div id="filePager" class="pager"></div>
                <p class="footnote">暂停会等待当前文件处理完成。作品资料与封面请在<a href="/">首页</a>维护。</p>
            </div>
        </section>
    </div>
</main>
<dialog id="workDialog" aria-labelledby="workTitle">
    <div class="dialog-heading"><div><h2 id="workTitle">选择已有作品</h2><p>按名称或 ID 找到对应的版本。</p></div><button id="closeDialog" class="icon-button" title="关闭" aria-label="关闭"><svg class="icon" aria-hidden="true"><use href="#qbt-close"/></svg></button></div>
    <div class="dialog-body"><div class="search-field"><svg class="icon" aria-hidden="true"><use href="#qbt-search"/></svg><input id="workSearch" type="search" placeholder="搜索作品名称或 ID" aria-label="搜索作品" maxlength="100"></div><div id="workResults"></div><div id="workPager" class="pager"></div></div>
</dialog>
@include('qbt.script')
</body>
</html>
