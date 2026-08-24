<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

if (current_user_id()) {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

$pdo = get_pdo();
$products = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY category, name')->fetchAll();

$categoryMeta = [
    'hosting' => ['label' => 'Hosting', 'icon' => '🖥️'],
    'vps'     => ['label' => 'VPS Hosting', 'icon' => '⚡'],
    'domain'  => ['label' => 'Domains', 'icon' => '🌐'],
    'ssl'     => ['label' => 'SSL Certificates', 'icon' => '🔒'],
];

$byCategory = [];
foreach ($products as $p) {
    $byCategory[$p['category']][] = $p;
}
// Keep only categories that actually have active products, in a stable order.
$activeCategories = array_values(array_filter(array_keys($categoryMeta), fn($c) => !empty($byCategory[$c])));
if (!$activeCategories && $products) {
    // Fallback: any category present in data but not in our known list.
    $activeCategories = array_keys($byCategory);
}
$firstCategory = $activeCategories[0] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_BRAND_NAME) ?> — Hosting, Domains, SSL &amp; VPS</title>
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/landing.css">
</head>
<body>

<header class="landing-nav">
    <div class="brand"><?= e(APP_BRAND_NAME) ?></div>
    <nav>
        <span class="mega-wrap">
            <button type="button" class="mega-trigger" id="megaTrigger">Products <span class="caret">▾</span></button>
            <div class="mega-panel" id="megaPanel">
                <?php foreach ($categoryMeta as $cat => $meta): ?>
                    <a class="mega-item" href="#plans" data-cat="<?= e($cat) ?>">
                        <span class="mega-icon"><?= e($meta['icon']) ?></span>
                        <span>
                            <h4><?= e($meta['label']) ?></h4>
                            <p><?= e(!empty($byCategory[$cat]) ? (count($byCategory[$cat]) . ' plan(s) available') : 'Coming soon') ?></p>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </span>
        <a href="#plans">Pricing</a>
        <a href="/login">Log in</a>
        <a href="/admin/login">Admin login</a>
        <a href="/register" class="nav-cta">Sign up</a>
    </nav>
</header>

<section class="hero">
    <div class="hero-inner">
        <h1>Plans &amp; pricing</h1>
        <div class="hero-badges">
            <span>🛡️ 30-day money-back guarantee</span>
            <span>🎧 24/7 support</span>
            <span>🔄 Cancel anytime</span>
        </div>
        <?php if ($activeCategories): ?>
        <div class="tabs" id="categoryTabs">
            <?php foreach ($activeCategories as $i => $cat): ?>
                <button type="button" class="tab-btn<?= $i === 0 ? ' active' : '' ?>" data-cat="<?= e($cat) ?>">
                    <?= e($categoryMeta[$cat]['icon'] ?? '📦') ?> <?= e($categoryMeta[$cat]['label'] ?? ucfirst($cat)) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="plans-section" id="plans">
    <div class="plans-inner">
        <div class="plans-head">
            <h2 id="plansHeading"><?= $firstCategory ? e($categoryMeta[$firstCategory]['label'] ?? ucfirst($firstCategory)) : 'Plans' ?></h2>
            <select class="tenure-select" id="tenureSelect">
                <option value="12">12 months plan</option>
                <option value="24">24 months plan (₹500 off)</option>
                <option value="36">36 months plan (₹1000 off)</option>
            </select>
        </div>

        <?php if (!$products): ?>
            <p class="empty-plans">Plans are being set up — check back shortly.</p>
        <?php else: ?>
            <?php foreach ($activeCategories as $cat): ?>
                <div class="plan-grid category-panel" data-cat="<?= e($cat) ?>" <?= $cat !== $firstCategory ? 'style="display:none"' : '' ?>>
                    <?php foreach ($byCategory[$cat] as $i => $p): ?>
                        <div class="plan-card<?= $i === 1 ? ' featured' : '' ?>"
                             data-base="<?= (float) $p['base_price_12mo'] ?>"
                             data-id="<?= (int) $p['id'] ?>">
                            <?php if ($i === 1): ?><span class="plan-badge">Popular</span><?php endif; ?>
                            <h3><?= e($p['name']) ?></h3>
                            <?php
                                $descParts = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) ($p['description'] ?? ''))));
                            ?>
                            <?php if ($descParts): ?>
                                <ul class="plan-features">
                                    <?php foreach ($descParts as $part): ?>
                                        <li><?= e($part) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="plan-desc">Full details available after login.</p>
                            <?php endif; ?>
                            <div class="plan-strike price-strike"></div>
                            <div class="plan-price"><span class="price-currency">₹</span><span class="price-amount"></span><span> /mo</span></div>
                            <a class="plan-cta" href="/checkout?product_id=<?= (int) $p['id'] ?>">Choose plan</a>
                            <p class="plan-fine price-fine"></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="features-section">
    <div class="features-inner">
        <h2>Everything you need, in one dashboard</h2>
        <div class="feature-grid">
            <div class="feature-item"><div class="feature-icon">📩</div><h3>Email OTP signup</h3><p>Fast, secure account verification — no passwords floating around.</p></div>
            <div class="feature-item"><div class="feature-icon">💳</div><h3>UPI payments</h3><p>Pay via UPI QR and submit your UTR — verified by our team quickly.</p></div>
            <div class="feature-item"><div class="feature-icon">🔑</div><h3>Instant API access</h3><p>Get your API key automatically once your order is approved.</p></div>
            <div class="feature-item"><div class="feature-icon">📊</div><h3>Simple dashboard</h3><p>Track every order, renewal and credential in one place.</p></div>
        </div>
    </div>
</section>

<footer class="landing-footer">
    <div><?= e(APP_BRAND_NAME) ?> &copy; <?= date('Y') ?></div>
    <div><a href="/register">Sign up</a> · <a href="/login">Log in</a> · <a href="/admin/login">Admin</a></div>
</footer>

<script>
(function () {
    var DISCOUNTS = { 12: 0, 24: 500, 36: 1000 };
    var tenureSelect = document.getElementById('tenureSelect');
    var tabs = document.querySelectorAll('.tab-btn');
    var panels = document.querySelectorAll('.category-panel');
    var heading = document.getElementById('plansHeading');
    var megaTrigger = document.getElementById('megaTrigger');
    var megaPanel = document.getElementById('megaPanel');

    if (megaTrigger && megaPanel) {
        megaTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            megaTrigger.classList.toggle('open');
            megaPanel.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!megaPanel.contains(e.target) && e.target !== megaTrigger) {
                megaTrigger.classList.remove('open');
                megaPanel.classList.remove('open');
            }
        });
        megaPanel.querySelectorAll('.mega-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var cat = item.getAttribute('data-cat');
                var tabBtn = document.querySelector('.tab-btn[data-cat="' + cat + '"]');
                if (tabBtn) { tabBtn.click(); }
                megaTrigger.classList.remove('open');
                megaPanel.classList.remove('open');
            });
        });
    }

    function renderPrices() {
        var tenure = parseInt(tenureSelect.value, 10);
        var months = tenure;
        var discount = DISCOUNTS[tenure] || 0;

        document.querySelectorAll('.plan-card').forEach(function (card) {
            var base = parseFloat(card.getAttribute('data-base')) || 0;
            var totalBase = base * (tenure / 12);
            var totalFinal = Math.max(0, totalBase - discount);
            var perMonth = totalFinal / months;
            var perMonthBase = totalBase / months;

            var amountEl = card.querySelector('.price-amount');
            var strikeEl = card.querySelector('.price-strike');
            var fineEl = card.querySelector('.price-fine');

            amountEl.textContent = perMonth.toFixed(0);
            if (discount > 0) {
                strikeEl.textContent = '₹' + perMonthBase.toFixed(0) + '/mo';
                strikeEl.style.visibility = 'visible';
            } else {
                strikeEl.textContent = '';
                strikeEl.style.visibility = 'hidden';
            }
            fineEl.textContent = 'Billed ₹' + totalFinal.toFixed(0) + ' for ' + months + ' months' +
                (discount > 0 ? ' (₹' + discount.toFixed(0) + ' off)' : '') + '.';
        });
    }

    tenureSelect.addEventListener('change', renderPrices);

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            var cat = tab.getAttribute('data-cat');
            panels.forEach(function (p) {
                p.style.display = (p.getAttribute('data-cat') === cat) ? '' : 'none';
            });
            var labelEl = tab.textContent.trim();
            if (heading) { heading.textContent = labelEl; }
            observeCards();
        });
    });

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    function observeCards() {
        document.querySelectorAll('.plan-card:not(.in-view), .feature-item:not(.in-view)').forEach(function (el) {
            io.observe(el);
        });
    }

    renderPrices();
    observeCards();
})();
</script>

</body>
</html>
