@php
    $footerName = $footerName ?? ($location->name ?? config('app.name', 'Gymie'));
@endphp
<footer class="public-footer">
    <div class="public-footer__inner">
        <span class="public-footer__copy">{{ __('app.legal.copyright', ['year' => date('Y'), 'name' => $footerName]) }} {{ __('app.legal.rights_reserved') }}</span>
        <span class="public-footer__dot" aria-hidden="true">·</span>
        <span class="public-footer__agree">{{ __('app.legal.agree_prefix') }} <button type="button" class="public-footer__link" data-terms-open>{{ __('app.legal.terms_link') }}</button></span>
    </div>
</footer>

<div id="public-terms-modal" class="public-terms-modal" hidden aria-hidden="true">
    <div class="public-terms-modal__overlay" data-terms-close></div>
    <div class="public-terms-modal__panel" role="dialog" aria-modal="true" aria-labelledby="public-terms-title">
        <div class="public-terms-modal__header">
            <h2 id="public-terms-title" class="public-terms-modal__title">{{ __('app.legal.modal_title') }}</h2>
            <button type="button" class="public-terms-modal__close" aria-label="{{ __('app.legal.close') }}" data-terms-close>&times;</button>
        </div>
        <div class="public-terms-modal__body">
            <p class="public-terms-modal__intro">{{ __('app.legal.modal_intro', ['name' => $footerName]) }}</p>
            <ol class="public-terms-modal__list">
                <li>{{ __('app.legal.item_data') }}</li>
                <li>{{ __('app.legal.item_purpose') }}</li>
                <li>{{ __('app.legal.item_retention') }}</li>
                <li>{{ __('app.legal.item_security') }}</li>
                <li>{{ __('app.legal.item_consent') }}</li>
            </ol>
        </div>
    </div>
</div>

<style>
    .public-footer {
        width: 100%;
        max-width: 420px;
        margin: 24px auto 0;
        padding: 16px 12px calc(16px + env(safe-area-inset-bottom, 0px)) 12px;
        text-align: center;
    }
    .public-footer__inner {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 6px 8px;
        font-size: 0.75rem;
        line-height: 1.4;
        color: var(--c-text-muted);
    }
    .public-footer__copy {
        white-space: nowrap;
    }
    .public-footer__dot {
        opacity: 0.5;
    }
    .public-footer__agree {
        display: inline;
    }
    .public-footer__link {
        background: none;
        border: none;
        padding: 0;
        font: inherit;
        font-weight: 600;
        color: var(--c-base);
        text-decoration: underline;
        text-underline-offset: 2px;
        cursor: pointer;
    }
    .public-footer__link:hover {
        color: var(--c-hover);
    }
    .public-footer__link:focus-visible {
        outline: 2px solid var(--c-ring);
        outline-offset: 2px;
        border-radius: 2px;
    }
    .public-terms-modal {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .public-terms-modal[hidden] {
        display: none !important;
    }
    .public-terms-modal__overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.48);
        backdrop-filter: blur(2px);
    }
    .public-terms-modal__panel {
        position: relative;
        width: 100%;
        max-width: 560px;
        max-height: min(80vh, 640px);
        display: flex;
        flex-direction: column;
        background: var(--c-surface);
        border: 1px solid var(--c-border);
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
        overflow: hidden;
        animation: publicTermsIn 0.22s ease-out;
    }
    @keyframes publicTermsIn {
        from { opacity: 0; transform: translateY(10px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .public-terms-modal__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px 14px;
        border-bottom: 1px solid var(--c-border);
        flex-shrink: 0;
    }
    .public-terms-modal__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: var(--c-text);
        line-height: 1.3;
    }
    .public-terms-modal__close {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--c-border);
        border-radius: 8px;
        background: var(--c-bg-a);
        color: var(--c-text-muted);
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
    }
    .public-terms-modal__close:hover {
        background: var(--c-bg-b);
        color: var(--c-text);
    }
    .public-terms-modal__close:focus-visible {
        outline: 2px solid var(--c-ring);
        outline-offset: 2px;
    }

    .public-terms-modal__body {
        padding: 16px 20px;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }
    .public-terms-modal__intro {
        margin: 0 0 12px;
        font-size: 0.875rem;
        line-height: 1.5;
        color: var(--c-text);
    }
    .public-terms-modal__list {
        margin: 0;
        padding-inline-start: 20px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        font-size: 0.8125rem;
        line-height: 1.55;
        color: var(--c-text-muted);
    }
    .public-terms-modal__list li::marker {
        color: var(--c-base);
        font-weight: 600;
    }

    .public-main {
        flex: 1 1 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 12px 0;
    }
    @media (max-width: 480px) {
        .public-terms-modal {
            padding: 12px;
            align-items: flex-end;
        }
        .public-terms-modal__panel {
            max-height: 88vh;
            border-radius: 16px;
            margin-bottom: 8px;
        }
    }
</style>

<script>
(function () {
    const modal = document.getElementById('public-terms-modal');
    if (!modal) return;
    const openBtns = document.querySelectorAll('[data-terms-open]');
    const closeEls = modal.querySelectorAll('[data-terms-close]');
    const panel = modal.querySelector('.public-terms-modal__panel');
    let lastFocus = null;

    function open() {
        lastFocus = document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        const focusable = panel.querySelector('button');
        if (focusable) focusable.focus();
        document.addEventListener('keydown', onKey);
    }

    function close() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKey);
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    openBtns.forEach(function (btn) {
        btn.addEventListener('click', open);
    });
    closeEls.forEach(function (el) {
        el.addEventListener('click', close);
    });
    // Close on overlay handled via data-terms-close above; prevent panel clicks from bubbling
    if (panel) {
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
    }
})();
</script>
