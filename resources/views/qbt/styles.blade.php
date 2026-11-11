<style>
    :root {
        color-scheme: light;
        font-family: 'Inter', 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif;
        color: #233b30;
        background: #f5f7f6;
        font-synthesis: none;
        --green: #286247;
        --green-dark: #1e5039;
        --muted: #6c7c72;
        --line: #e1e8e3;
        --soft: #f3f7f4;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-size: 15px; line-height: 1.6; }
    body:has(dialog[open]) { overflow: hidden; }
    button, input, select { font: inherit; }
    button, a { -webkit-tap-highlight-color: transparent; }
    button, a, input, select { transition: background .16s, border-color .16s, color .16s, box-shadow .16s; }
    button { display: inline-flex; justify-content: center; align-items: center; gap: 8px; min-height: 42px; padding: 9px 16px; border: 1px solid var(--green); border-radius: 9px; background: var(--green); color: white; font-weight: 550; cursor: pointer; }
    button:hover:not(:disabled) { background: var(--green-dark); border-color: var(--green-dark); }
    button:disabled { cursor: default; opacity: .45; }
    button.secondary { background: white; border-color: var(--line); color: #465d4e; }
    button.secondary:hover:not(:disabled) { background: var(--soft); border-color: #c4d6c9; }
    button.quiet { background: transparent; border-color: transparent; color: var(--muted); }
    button.quiet:hover:not(:disabled) { background: #eaf1ec; color: var(--green); border-color: transparent; }
    button.small { min-height: 32px; padding: 5px 10px; border-radius: 7px; font-size: 13px; }
    button.icon-button { min-height: 34px; width: 34px; padding: 7px; background: transparent; border-color: transparent; color: var(--muted); }
    button.icon-button:hover:not(:disabled) { background: var(--soft); border-color: transparent; color: var(--green); }
    button:focus-visible, a:focus-visible { outline: 3px solid #9ec6ad; outline-offset: 3px; }
    input, select { width: 100%; min-width: 0; max-width: 100%; height: 42px; padding: 9px 12px; border: 1px solid #d8e2db; border-radius: 8px; background: white; color: inherit; outline: none; }
    input::placeholder { color: #95a198; }
    input:focus, select:focus { border-color: #86b59a; box-shadow: 0 0 0 3px #2862470d; }
    input:disabled, select:disabled { background: #f5f7f5; color: var(--muted); }
    input[type=checkbox] { width: 17px; height: 17px; padding: 0; margin: 3px 0 0; flex-shrink: 0; accent-color: var(--green); cursor: pointer; }
    input[type=checkbox]:disabled { cursor: default; opacity: .5; }
    a { color: var(--green); text-decoration: none; }
    a:hover { color: var(--green-dark); }
    h1, h2, h3, p { margin: 0; }
    h1 { font-size: 29px; font-weight: 650; letter-spacing: -.045em; line-height: 1.35; }
    h2 { font-size: 17px; font-weight: 600; }
    h3 { font-size: 16px; font-weight: 600; }
    small, .muted { color: var(--muted); }
    small { font-size: 12px; }
    .icon-sprite { position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none; }
    .icon { width: 18px; height: 18px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 1.65; stroke-linecap: round; stroke-linejoin: round; }
    .site-header { background: #fff; border-bottom: 1px solid var(--line); }
    .header-inner { max-width: 1480px; min-height: 72px; margin: auto; padding: 14px 32px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
    .brand { display: flex; align-items: center; gap: 11px; color: #233b30; font-size: 17px; font-weight: 650; white-space: nowrap; }
    .brand img { display: block; }
    .header-actions { display: flex; align-items: center; gap: 22px; font-size: 13px; }
    .account { display: inline-flex; align-items: center; gap: 7px; color: var(--muted); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .account-dot { display: block; width: 7px; height: 7px; flex-shrink: 0; border-radius: 50%; background: #7f9b87; }
    .home-link { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .home-link .icon { width: 16px; height: 16px; }
    .app { max-width: 1480px; margin: auto; padding: 30px 32px 40px; }
    .page-heading { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
    .page-heading p { margin-top: 7px; color: var(--muted); font-size: 14px; }
    .source-path { display: flex; align-items: center; gap: 8px; min-width: 0; max-width: 44%; padding: 8px 12px; border: 1px solid var(--line); border-radius: 8px; background: #eef2ef; color: #748178; font-size: 12px; }
    .source-path .icon { width: 15px; height: 15px; }
    .source-path span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, monospace; }
    #message { display: flex; align-items: start; gap: 9px; padding: 12px 16px; margin-bottom: 18px; border: 1px solid #c9dfd1; border-radius: 9px; background: #edf6f0; color: var(--green); font-size: 14px; overflow-wrap: anywhere; }
    #message::before { content: ''; width: 7px; height: 7px; flex-shrink: 0; border-radius: 50%; background: currentColor; margin-top: 8px; }
    #message.error { border-color: #eed5ce; background: #fff4f0; color: #ad4c37; }
    .layout { display: grid; grid-template-columns: 288px minmax(0, 1fr); gap: 22px; align-items: start; }
    .panel { min-width: 0; background: #fff; border: 1px solid var(--line); border-radius: 13px; box-shadow: 0 3px 12px #233b3003; }
    .folder-panel { padding: 18px 16px 14px; position: sticky; top: 20px; }
    .panel-heading, .section-title { display: flex; align-items: center; gap: 9px; }
    .panel-heading { justify-content: space-between; margin-bottom: 14px; }
    .count { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; padding: 1px 7px; border-radius: 6px; background: #edf2ee; color: #738176; font-size: 11px; font-weight: 550; font-variant-numeric: tabular-nums; }
    .navigation { display: flex; gap: 6px; margin-bottom: 13px; }
    .navigation .icon { width: 14px; height: 14px; }
    .search-field { position: relative; min-width: 0; }
    .search-field > .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #97a49a; width: 16px; height: 16px; pointer-events: none; }
    .search-field input { padding-left: 36px; background: #fafcfb; font-size: 13px; }
    #folders { max-height: calc(100dvh - 252px); overflow-y: auto; margin: 12px -6px 0; padding: 0 3px; scrollbar-width: thin; scrollbar-color: #d5dfd7 transparent; }
    button.folder { display: grid; grid-template-columns: 25px minmax(0, 1fr) 14px; width: 100%; text-align: left; gap: 10px; min-height: 54px; padding: 10px 8px; margin: 2px 0; border: 1px solid transparent; background: transparent; color: #40564a; border-radius: 8px; font-size: 14px; font-weight: 450; line-height: 1.65; }
    button.folder:hover:not(:disabled) { border-color: #e0ebe3; background: #f1f7f3; color: var(--green); }
    .folder-icon { display: grid; place-items: center; width: 25px; height: 30px; color: #6f987c; }
    .folder-icon .icon { width: 21px; height: 21px; }
    .folder-name { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; overflow-wrap: anywhere; }
    .folder-info { min-width: 0; }
    .folder-meta { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin-top: 5px; font-size: 10px; color: #7a8c7f; }
    .saved-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; border-radius: 5px; background: #f0f3f1; color: #7a8c7f; font-size: 11px; font-weight: 500; white-space: nowrap; }
    .saved-badge.saved { background: #e7f3e9; color: #286247; }
    .saved-badge .icon { width: 12px; height: 12px; }
    .folder-state-filter { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; }
    .folder-state-filter select { height: 32px; flex: 1; padding: 4px 8px; font-size: 12px; }
    .folder-arrow { width: 12px; height: 12px; color: #a4b2a8; }
    .import-panel { padding: 24px; }
    .directory-heading { display: flex; align-items: center; gap: 12px; padding-bottom: 21px; border-bottom: 1px solid #eaf0eb; }
    .directory-heading > div { min-width: 0; }
    .directory-title { flex: 1; }
    .directory-marker { display: grid; justify-items: end; gap: 5px; flex-shrink: 0; }
    .directory-marker small { color: #7a8c7f; font-size: 10px; }
    .heading-icon { display: grid; place-items: center; width: 42px; height: 42px; flex-shrink: 0; border: 1px solid #dce9e0; border-radius: 10px; background: #eff6f1; color: var(--green); }
    .heading-icon .icon { width: 22px; height: 22px; }
    .eyebrow { color: #8a988e; font-size: 11px; margin-bottom: 2px; }
    #currentPath { overflow-wrap: anywhere; font-size: 17px; line-height: 1.55; }
    .welcome { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 360px; padding: 36px 20px; text-align: center; }
    .welcome-icon { display: grid; place-items: center; width: 64px; height: 64px; margin-bottom: 18px; border: 1px solid #e4ece6; border-radius: 19px; background: #f4f8f5; color: #83a28c; }
    .welcome-icon .icon { width: 30px; height: 30px; stroke-width: 1.35; }
    .welcome h3 { font-size: 18px; font-weight: 550; }
    .welcome p { color: var(--muted); font-size: 13px; margin: 8px 0 5px; }
    .welcome small { color: #9ba69e; }
    .settings { display: grid; grid-template-columns: minmax(0, 1fr) 72px 112px 130px; gap: 12px; align-items: end; margin-top: 22px; }
    label, .work-field { display: grid; gap: 7px; min-width: 0; font-size: 13px; color: #64776a; }
    .settings input, .settings select { height: 58px; padding: 10px; font-size: 14px; }
    .settings select { padding-right: 5px; }
    button.work-picker { display: grid; grid-template-columns: 30px minmax(0, 1fr) 14px; justify-content: start; gap: 10px; width: 100%; height: 58px; padding: 8px 10px; border: 1px solid #d8e2db; background: white; color: #365141; text-align: left; font-weight: 450; }
    button.work-picker:hover:not(:disabled) { background: #f8fbf9; border-color: #9dbfa8; }
    button.work-picker.has-work { border-color: #b8d1bf; background: #f7fbf8; }
    .chosen-cover { width: 30px; height: 40px; object-fit: cover; border-radius: 4px; }
    .cover-placeholder { display: grid; place-items: center; width: 30px; height: 40px; flex-shrink: 0; border: 1px solid #e2ebe4; border-radius: 4px; background: #eef4ef; color: #89a591; }
    .cover-placeholder .icon { width: 17px; height: 17px; }
    .chosen-work { min-width: 0; display: grid; gap: 1px; }
    .chosen-work strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; font-weight: 550; }
    .chosen-work small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #829389; font-size: 11px; }
    .picker-arrow { width: 13px; height: 13px; color: #97aa9c; }
    .field-hint { margin-top: 10px; font-size: 12px; color: #8a988e; }
    .import-status { margin: 20px 0 24px; padding: 16px; border: 1px solid #e0eae2; border-radius: 10px; background: #f6f9f6; }
    .status-heading { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .status-label { display: inline-flex; align-items: center; gap: 7px; color: #637d6b; font-size: 12px; font-weight: 550; }
    .status-label::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #9aafa0; }
    .is-running .status-label { color: var(--green); }
    .is-running .status-label::before { background: #4b8a61; box-shadow: 0 0 0 3px #4b8a6114; }
    .summary { display: flex; gap: 14px; flex-wrap: wrap; color: #7a8c7f; font-size: 12px; font-variant-numeric: tabular-nums; }
    .summary b { font-size: 12px; font-weight: 550; color: #55755e; margin-left: 3px; }
    .summary .has-errors, .summary .has-errors b { color: #ad5945; }
    progress { appearance: none; display: block; width: 100%; height: 5px; margin: 13px 0 14px; border: 0; border-radius: 9px; overflow: hidden; background: #e4ece6; accent-color: #58896b; }
    progress::-webkit-progress-bar { background: #e4ece6; border-radius: 9px; }
    progress::-webkit-progress-value { background: #58896b; border-radius: 9px; transition: width .2s; }
    progress::-moz-progress-bar { background: #58896b; border-radius: 9px; }
    #currentFile { margin: -2px 0 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #6f8677; font-size: 12px; }
    .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
    .actions button { min-height: 39px; padding: 7px 14px; font-size: 13px; }
    .actions .icon { width: 15px; height: 15px; }
    .actions small { margin-left: auto; color: #96a298; font-size: 11px; }
    .files-heading { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 13px; }
    .files-heading h3 { font-size: 15px; }
    .selection-count { color: #91a096; font-size: 11px; }
    .filters { display: flex; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .filters .search-field { flex: 1; max-width: 330px; }
    .filters .search-field input { height: 36px; font-size: 12px; }
    .selection-actions { display: flex; align-items: center; gap: 0; flex-shrink: 0; }
    .selection-actions button { font-size: 12px; min-height: 30px; padding: 4px 8px; }
    .selection-actions button:first-child { color: var(--green); }
    .sort-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 12px 0 8px; border-top: 1px solid #edf1ee; }
    .sort-toolbar > label { display: flex; align-items: center; gap: 8px; font-size: 12px; }
    .sort-toolbar select { width: 145px; height: 33px; font-size: 12px; padding: 5px 8px; }
    .bulk-move { display: flex; align-items: center; gap: 6px; margin-left: auto; }
    .bulk-move label { font-size: 12px; }
    .bulk-move input { width: 60px; height: 32px; padding: 5px 6px; font-size: 12px; }
    .sort-hint { margin-bottom: 12px; color: #7a8c7f; font-size: 11px; }
    .file-table-heading { display: grid; grid-template-columns: minmax(0, 1fr) 66px 108px; gap: 10px; padding: 9px 11px 9px 70px; border-top: 1px solid #edf1ee; border-bottom: 1px solid #e8eee9; background: #fafcfb; color: #9aa79d; font-size: 10px; }
    .file { display: grid; grid-template-columns: 17px 25px minmax(0, 1fr) 66px 108px; align-items: center; gap: 10px; padding: 13px 10px; border-bottom: 1px solid #edf1ee; transition: background .16s; }
    .file-order { display: flex; align-items: center; justify-content: end; gap: 3px; }
    .file-order button { width: 25px; min-height: 30px; padding: 4px; font-size: 14px; }
    .file-order input { width: 48px; height: 30px; padding: 4px 2px; font-size: 12px; text-align: center; appearance: textfield; }
    .file-order input::-webkit-inner-spin-button { appearance: none; }
    .file-status { justify-self: start; }
    .file:hover { background: #fbfdfb; }
    .file input { margin: 0; }
    .audio-icon { width: 25px; height: 30px; display: grid; place-items: center; color: #a0b4a6; }
    .audio-icon .icon { width: 18px; height: 18px; stroke-width: 1.45; }
    .file-info { min-width: 0; }
    .filename { overflow-wrap: anywhere; font-size: 14px; line-height: 1.6; }
    .file-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 3px; color: #87998d; font-size: 11px; }
    .file-meta a { color: #7c9886; }
    .file-group { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .file-status { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; background: #f1f4f2; color: #7a8d81; font-size: 11px; white-space: nowrap; }
    .file-status.imported { color: #4c815e; background: #edf6ef; }
    .file-status.referenced { color: #6483a0; background: #edf3f8; }
    .file-status.failed { color: #ad5945; background: #fff0e9; }
    .file-status .icon { width: 11px; height: 11px; stroke-width: 2; }
    .file-error { margin-top: 4px; font-size: 11px; overflow-wrap: anywhere; color: #b16b58; }
    .pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 20px; color: #96a299; font-size: 11px; font-variant-numeric: tabular-nums; }
    .pager button { min-height: 31px; font-size: 11px; padding: 5px 10px; }
    .footnote { margin-top: 20px; padding-top: 15px; border-top: 1px solid #edf1ee; color: #87998d; font-size: 12px; }
    .footnote a { color: #6f927b; }
    .empty { display: flex; flex-direction: column; align-items: center; gap: 9px; padding: 35px 14px; color: #95a49a; font-size: 12px; text-align: center; }
    .empty .icon { width: 25px; height: 25px; color: #b3c3b8; }
    #files > .empty { padding: 48px 16px; }
    dialog { width: min(640px, calc(100% - 32px)); max-height: calc(100dvh - 64px); padding: 0; overflow: auto; border: 1px solid #e0e8e2; border-radius: 15px; color: inherit; box-shadow: 0 24px 80px #153b2329; }
    dialog::backdrop { background: #172d2266; backdrop-filter: blur(3px); }
    .dialog-heading { display: flex; align-items: start; justify-content: space-between; gap: 16px; padding: 22px 24px 19px; border-bottom: 1px solid #eaf0eb; }
    .dialog-heading h2 { font-size: 19px; }
    .dialog-heading p { margin-top: 4px; color: #91a096; font-size: 12px; }
    .dialog-heading .icon-button { margin: -3px -5px 0 0; }
    .dialog-body { padding: 18px 24px 23px; }
    #workResults { margin-top: 13px; }
    button.work { display: flex; align-items: center; gap: 13px; width: 100%; padding: 12px 9px; border: 1px solid transparent; border-bottom-color: #edf1ee; border-radius: 8px; background: white; color: #3a5142; text-align: left; font-weight: 450; }
    button.work:hover, button.work.is-selected { background: #f1f7f3; border-color: #dceadf; }
    .work img, .work-cover { display: grid; place-items: center; width: 38px; height: 51px; flex-shrink: 0; border: 1px solid #e4ebe6; border-radius: 5px; object-fit: cover; background: #f0f5f1; color: #9bb0a1; }
    .work-cover .icon { width: 20px; height: 20px; }
    .work-info { min-width: 0; flex: 1; }
    .work-title { display: block; font-size: 15px; font-weight: 550; overflow-wrap: anywhere; }
    .work-subtitle { display: block; margin-top: 2px; color: #81958a; font-size: 12px; overflow-wrap: anywhere; }
    .work-meta { display: flex; gap: 9px; flex-wrap: wrap; margin-top: 5px; font-size: 11px; color: #87998d; }
    .work-meta span:first-child { color: #7b9985; }
    .work-arrow { width: 15px; height: 15px; color: #b0bfb5; }
    .is-selected .work-arrow { color: var(--green); }
    [hidden] { display: none !important; }
    @media (max-width: 1180px) {
        .layout { grid-template-columns: 250px minmax(0, 1fr); gap: 18px; }
        .settings { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .work-field { grid-column: 1 / -1; }
        .settings input, .settings select { height: 42px; }
        .filters { gap: 8px; }
        .selection-actions button { padding: 4px 5px; }
    }
    @media (max-width: 820px) {
        .header-inner { min-height: 64px; padding: 13px 20px; }
        .app { padding: 24px 20px; }
        .page-heading { align-items: start; gap: 12px; flex-direction: column; margin-bottom: 22px; }
        .source-path { max-width: 100%; padding: 6px 10px; }
        .layout { grid-template-columns: 1fr; gap: 16px; }
        .folder-panel { position: static; padding: 16px; }
        .folder-panel.is-leaf .navigation { margin-bottom: 0; }
        .folder-panel.is-leaf .search-field, .folder-panel.is-leaf #folders, .folder-panel.is-leaf .folder-state-filter { display: none; }
        #folders { max-height: 220px; margin-top: 8px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 3px 12px; }
        #folders > .empty { grid-column: 1 / -1; padding: 16px; }
        .navigation { margin-bottom: 10px; }
        .panel-heading { margin-bottom: 10px; }
        .import-panel { padding: 22px; }
        .welcome { min-height: 270px; }
        .filters .search-field { max-width: none; }
    }
    @media (max-width: 520px) {
        .header-inner { padding: 13px 16px; }
        .brand { gap: 8px; font-size: 15px; }
        .brand img { width: 25px; height: 25px; }
        .header-actions { gap: 13px; font-size: 12px; }
        .account { display: none; }
        .app { padding: 23px 12px; }
        h1 { font-size: 26px; }
        .page-heading { padding: 0 4px; }
        .page-heading p { font-size: 13px; }
        .source-path { font-size: 11px; }
        #folders { gap: 2px; }
        button.folder { grid-template-columns: 19px minmax(0, 1fr) 10px; gap: 7px; font-size: 13px; padding: 8px 6px; }
        .folder-icon { width: 19px; }
        .folder-icon .icon { width: 18px; height: 18px; }
        .folder-arrow { width: 10px; height: 10px; }
        .import-panel { padding: 18px 14px; }
        #currentPath { font-size: 15px; }
        .directory-heading { gap: 10px; padding-bottom: 18px; }
        .directory-heading { flex-wrap: wrap; }
        .directory-title { min-width: calc(100% - 50px) !important; }
        .directory-marker { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; margin-left: 46px; justify-items: start; }
        .heading-icon { width: 36px; height: 36px; border-radius: 9px; }
        .heading-icon .icon { width: 19px; height: 19px; }
        .settings { margin-top: 18px; gap: 12px 9px; }
        .field-hint { font-size: 11px; line-height: 1.8; }
        .import-status { padding: 14px 12px; margin: 18px 0 21px; }
        .status-heading { gap: 8px; }
        .summary { gap: 10px; font-size: 11px; }
        .summary b { font-size: 12px; }
        .actions { gap: 7px; }
        .actions small { width: 100%; margin: 2px 0 0; font-size: 10px; }
        .filters { flex-wrap: wrap; gap: 7px; margin-bottom: 12px; }
        .filters .search-field { flex-basis: 100%; }
        .selection-actions { margin-left: auto; }
        .file-table-heading { padding-left: 30px; grid-template-columns: minmax(0, 1fr) auto; }
        .order-heading { display: none; }
        .file { grid-template-columns: 17px minmax(0, 1fr) auto; gap: 9px; padding: 12px 4px; }
        .file-order { grid-column: 2 / -1; }
        .file-order button { min-height: 32px; width: 29px; }
        .file-order input { height: 32px; }
        .bulk-move { margin-left: 0; }
        .sort-toolbar { gap: 8px 6px; }
        .sort-toolbar select { width: 135px; }
        .audio-icon { display: none; }
        .filename { font-size: 14px; }
        .file-status { align-self: start; margin-top: 2px; padding: 3px 5px; font-size: 11px; }
        .file-status .icon { display: none; }
        .file-meta { font-size: 11px; gap: 5px; }
        .pager { gap: 10px; font-size: 10px; }
        .pager button { font-size: 10px; padding: 4px 8px; }
        .footnote { font-size: 11px; }
        dialog { width: calc(100% - 24px); max-height: calc(100dvh - 32px); border-radius: 12px; }
        .dialog-heading { padding: 20px 18px 16px; }
        .dialog-body { padding: 15px 14px 19px; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { transition: none !important; scroll-behavior: auto !important; }
    }
</style>
