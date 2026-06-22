<style>
    .legal-card {
        background: #141414;
        border: 1px solid rgba(255,165,38,0.12);
        border-radius: 20px;
        padding: 40px 24px;
        box-shadow: 0 0 40px rgba(255,165,38,0.05);
    }
    @media (min-width: 992px) { .legal-card { padding: 50px 60px; } }
    .legal-title {
        font-size: 26px; font-weight: 800; color: #fff; text-align: center;
        text-shadow: 0 0 20px rgba(255,165,38,0.3); margin: 0 0 8px;
    }
    @media (min-width: 992px) { .legal-title { font-size: 34px; } }
    .legal-date { text-align: center; font-size: 14px; color: #888; margin: 0; }
    .legal-divider {
        height: 1px; margin: 28px 0 36px;
        background: linear-gradient(90deg, transparent, rgba(255,165,38,0.3), transparent);
    }
    .legal-body { color: #C8C8C8; font-size: 15px; line-height: 1.8; }
    @media (min-width: 992px) { .legal-body { font-size: 16px; } }
    .legal-body h4, .legal-body h5 { color: #fff; font-weight: 700; margin: 2em 0 .8em; }
    .legal-body h4 { font-size: 20px; padding-bottom: .4em; border-bottom: 1px solid rgba(255,165,38,0.1); }
    .legal-body h5 { font-size: 16px; color: #ddd; margin-top: 1.4em; }
    .legal-body p { margin: 0 0 1.2em; color: #C8C8C8; }
    .legal-body ul, .legal-body ol { margin: 0 0 1.2em; padding-left: 1.5em; color: #C8C8C8; }
    .legal-body li { margin-bottom: .5em; }
    .legal-body ul li::marker { color: #FFA526; }
    .legal-body ol li::marker { color: #FFA526; font-weight: 600; }
    .legal-body a { color: #FFA526; text-decoration: underline; text-underline-offset: 3px; transition: color .2s; }
    .legal-body a:hover { color: #FFC565; }
    .legal-body strong, .legal-body b { color: #fff; font-weight: 600; }
    .legal-body hr { border: 0; height: 1px; background: rgba(255,255,255,0.06); margin: 1.5em 0; }
    .legal-body .alert {
        padding: 16px 20px; margin: 1.5em 0; border-radius: 10px;
        font-size: 15px; line-height: 1.7; border: 1px solid;
    }
    .legal-body .alert-warning { background: rgba(255,165,38,0.08); border-color: rgba(255,165,38,0.25); color: #FFD080; }
    .legal-body ul.list-unstyled { list-style: none; padding-left: 0; }
</style>
