// Account menu (top right): close it with Escape or by clicking elsewhere.
document.querySelectorAll('details.account-menu').forEach(function (menu) {
  document.addEventListener('click', function (e) {
    if (menu.open && !menu.contains(e.target)) menu.open = false;
  });
  menu.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) {
      menu.open = false;
      menu.querySelector('summary').focus();
    }
  });
});

document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    navigator.clipboard.writeText(btn.dataset.copy).then(function () {
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = 'Copy link'; }, 1500);
    });
  });
});

// Ask before deleting or sending. Works on a whole form or a single button.
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm(form.dataset.confirm)) e.preventDefault();
  });
});
document.querySelectorAll('button[data-confirm]').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    if (!confirm(btn.dataset.confirm)) e.preventDefault();
  });
});

// Ticking invitations in the guest table for "Send selected" and "Delete
// selected". The checkboxes belong to the form above the table (form="bulk"),
// so they work without this script too; this adds "select all", a count,
// labels that say send or resend, and confirmations with numbers in.
(function () {
  var form = document.getElementById('bulk');
  if (!form) return;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"][form="bulk"]'));
  var all = document.getElementById('select-all');
  var count = document.getElementById('bulk-count');
  var del = form.querySelector('button[value="delete_selected"]');
  var send = form.querySelector('button[value="send_selected"]');
  var sendLabel = send.querySelector('.bulk-send-label');
  function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

  function update() {
    var ticked = boxes.filter(function (b) { return b.checked; });
    var n = ticked.length;
    var sendable = ticked.filter(function (b) { return b.dataset.sendable === '1'; });
    var again = sendable.filter(function (b) { return b.dataset.sent === '1'; }).length;
    var skipped = n - sendable.length;
    var tooMany = n > 950;   // PHP only receives about 1,000 form fields at once
    count.textContent = tooMany ? n + ' selected: too many for one go, so untick some and do it in two goes.'
      : n ? n + ' selected' : 'Tick invitations to send or delete several at once.';

    del.disabled = n === 0 || tooMany;
    del.dataset.confirm = 'Delete ' + (n === 1 ? 'this invitation' : 'these ' + n + ' invitations') + "? This can't be undone.";

    send.disabled = sendable.length === 0 || tooMany;
    sendLabel.textContent = again === 0 ? 'Send selected' : again === sendable.length ? 'Resend selected' : 'Send / resend selected';
    var msg = 'Send the invitation to ' + plural(sendable.length, 'guest') + '?';
    if (again && again === sendable.length) {
      msg += again === 1 ? " They've had it before, so they'll get it again." : " They've all had it before, so they'll get it again.";
    } else if (again) {
      msg += ' ' + again + ' of them ' + (again === 1 ? 'has' : 'have') + ' had it before and will get it again.';
    }
    if (skipped) msg += ' ' + plural(skipped, 'ticked invitation') + ' with no email address will be skipped.';
    send.dataset.confirm = msg;
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
    boxes.forEach(function (b) { b.closest('tr').classList.toggle('selected', b.checked); });
  }
  boxes.forEach(function (b) { b.addEventListener('change', update); });
  all.hidden = false;
  all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    update();
  });
  update();
})();

// Collapsible cards (e.g. "Add invitations"): the double chevron in the
// heading opens and closes the card, remembered in this browser. Same as the
// guest page's cards, with its own list.
(function () {
  var STORE = 'admin-collapsed-cards';
  var collapsed = [];
  try { collapsed = JSON.parse(localStorage.getItem(STORE) || '[]') || []; } catch (e) { collapsed = []; }

  function set(card, open) {
    var btn = card.querySelector('.card-toggle');
    var body = card.querySelector('.card-body');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('title', open ? 'Collapse' : 'Expand');
    body.hidden = !open;
    card.classList.toggle('is-collapsed', !open);
  }

  document.querySelectorAll('.card.collapsible').forEach(function (card) {
    var key = card.dataset.key;
    if (!card.querySelector('.card-toggle') || !card.querySelector('.card-body')) return;
    set(card, collapsed.indexOf(key) === -1);
    card.querySelector('.card-toggle').addEventListener('click', function () {
      var open = this.getAttribute('aria-expanded') !== 'true';
      set(card, open);
      collapsed = collapsed.filter(function (k) { return k !== key; });
      if (!open) collapsed.push(key);
      try { localStorage.setItem(STORE, JSON.stringify(collapsed)); } catch (e) { /* private mode etc. */ }
    });
  });
})();

// Formatting toolbar for the section editor: wraps or prefixes the selected
// text in the textarea with the simple Markdown the guest page understands.
document.querySelectorAll('[data-md]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = document.querySelector('[data-md-target]');
    if (!box) return;
    var start = box.selectionStart, end = box.selectionEnd;
    var text = box.value, sel = text.slice(start, end);
    var before = text.slice(0, start), after = text.slice(end);
    var insert, selStart, selEnd;

    function wrap(mark, placeholder) {
      var inner = sel || placeholder;
      insert = mark + inner + mark;
      selStart = start + mark.length;
      selEnd = selStart + inner.length;
    }
    // Prefix every selected line (or the current line) with a marker.
    function prefixLines(marker) {
      var lineStart = before.lastIndexOf('\n') + 1;
      var block = text.slice(lineStart, end) || '';
      var lines = block.split('\n').map(function (l) { return l.indexOf(marker) === 0 ? l : marker + l; });
      before = text.slice(0, lineStart);
      insert = lines.join('\n');
      selStart = lineStart;
      selEnd = lineStart + insert.length;
    }

    switch (btn.dataset.md) {
      case 'bold': wrap('**', 'bold text'); break;
      case 'italic': wrap('*', 'italic text'); break;
      case 'heading': prefixLines('## '); break;
      case 'list': prefixLines('- '); break;
      case 'link':
        var label = sel || 'link text';
        insert = '[' + label + '](https://)';
        selStart = start + label.length + 3;           // inside the ( )
        selEnd = selStart + 'https://'.length;
        break;
    }
    box.value = before + insert + after;
    box.focus();
    box.setSelectionRange(selStart, selEnd);
  });
});

// Text size menu: wraps each selected line in [size]…[/size].
document.querySelectorAll('[data-md-size]').forEach(function (menu) {
  menu.addEventListener('change', function () {
    var size = menu.value;
    var box = document.querySelector('[data-md-target]');
    menu.value = '';
    if (!size || !box) return;
    var start = box.selectionStart, end = box.selectionEnd;
    var sel = box.value.slice(start, end) || 'text';
    // Remove any size already applied to the selection, then apply the new one.
    var wrapped = sel.split('\n').map(function (line) {
      line = line.replace(/\[\/?(small|large|xlarge)\]/g, '');
      return line.trim() ? '[' + size + ']' + line + '[/' + size + ']' : line;
    }).join('\n');
    box.value = box.value.slice(0, start) + wrapped + box.value.slice(end);
    box.focus();
    box.setSelectionRange(start, start + wrapped.length);
  });
});

// Dietary donut: hovering a segment or its table row highlights both.
(function () {
  var chart = document.querySelector('.diet-chart');
  if (!chart) return;
  function mark(seg) {
    chart.querySelectorAll('[data-seg]').forEach(function (el) {
      el.classList.toggle('dim', seg !== null && el.dataset.seg !== seg);
      el.classList.toggle('hl', el.dataset.seg === seg);
    });
  }
  chart.querySelectorAll('[data-seg]').forEach(function (el) {
    el.addEventListener('mouseenter', function () { mark(el.dataset.seg); });
    el.addEventListener('mouseleave', function () { mark(null); });
  });
})();
