(function () {
  const wrap = document.querySelector('.tenure-options');
  if (!wrap) return;

  const basePrice12mo = parseFloat(wrap.dataset.basePrice || '0');
  const discounts = { 12: 0, 24: 500, 36: 1000 };

  const baseEl = document.getElementById('base-amount');
  const discountEl = document.getElementById('discount-amount');
  const finalEl = document.getElementById('final-amount');

  function fmt(n) {
    return n.toFixed(2);
  }

  function update() {
    const selected = wrap.querySelector('input[name="tenure_months"]:checked');
    const months = selected ? parseInt(selected.value, 10) : 12;
    const base = basePrice12mo * (months / 12);
    const discount = discounts[months] || 0;
    const final = Math.max(0, base - discount);

    baseEl.textContent = fmt(base);
    discountEl.textContent = fmt(discount);
    finalEl.textContent = fmt(final);
  }

  wrap.addEventListener('change', update);
  update();

  // NOTE: this is display-only. The server recalculates and is the source of
  // truth for the amount actually charged — see calculate_price() in
  // includes/functions.php. Never trust a client-submitted total.
})();
