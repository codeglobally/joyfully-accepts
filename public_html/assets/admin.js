// Phrases in the admin's language, from the page (see js_phrases()). Plural
// phrases come as their forms and are picked with the browser's plural rules.
var PHRASES = (function () {
  var el = document.getElementById('phrases');
  try { return el ? JSON.parse(el.dataset.phrases) : {}; } catch (e) { return {}; }
})();
function phrase(key, vars) {
  var text = PHRASES[key] || key;
  vars = vars || {};
  if (typeof text === 'object') {
    var form = 'other';
    try { form = new Intl.PluralRules(document.documentElement.lang).select(vars.count || 0); } catch (e) {}
    text = text[form] || text.other;
  }
  return text.replace(/\{(\w+)\}/g, function (m, name) { return name in vars ? vars[name] : m; });
}

// Small forms that save as soon as a box is ticked (data-autosubmit), such as
// the People tab's feedback-email switches. Their Save buttons are only for
// browsers without JavaScript.
document.querySelectorAll('form[data-autosubmit]').forEach(function (form) {
  form.querySelectorAll('[data-autosubmit-button]').forEach(function (b) { b.hidden = true; });
  form.addEventListener('change', function () { form.submit(); });
});

// Account menu (top right) and language pickers: close with Escape or by clicking elsewhere.
document.querySelectorAll('details.account-menu, details.lang-menu').forEach(function (menu) {
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
      btn.textContent = phrase('admin.copied');
      setTimeout(function () { btn.textContent = phrase('admin.copy_link'); }, 1500);
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

  function update() {
    var ticked = boxes.filter(function (b) { return b.checked; });
    var n = ticked.length;
    var sendable = ticked.filter(function (b) { return b.dataset.sendable === '1'; });
    var again = sendable.filter(function (b) { return b.dataset.sent === '1'; }).length;
    var skipped = n - sendable.length;
    var tooMany = n > 950;   // PHP only receives about 1,000 form fields at once
    count.textContent = tooMany ? phrase('admin.bulk.js_too_many', { count: n })
      : n ? phrase('admin.bulk.js_selected', { count: n }) : phrase('admin.bulk.hint');

    del.disabled = n === 0 || tooMany;
    del.dataset.confirm = phrase('admin.bulk.js_confirm_delete', { count: n });

    send.disabled = sendable.length === 0 || tooMany;
    sendLabel.textContent = phrase(again === 0 ? 'admin.bulk.send' : again === sendable.length ? 'admin.bulk.resend' : 'admin.bulk.send_resend');
    var msg = phrase('admin.bulk.js_confirm_send', { count: sendable.length });
    if (again && again === sendable.length) {
      msg += ' ' + phrase(again === 1 ? 'admin.bulk.js_again_one' : 'admin.bulk.js_again_all');
    } else if (again) {
      msg += ' ' + phrase('admin.bulk.js_again_some', { count: again });
    }
    if (skipped) msg += ' ' + phrase('admin.bulk.js_skipped', { count: skipped });
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
      case 'bold': wrap('**', phrase('admin.md.js_bold')); break;
      case 'italic': wrap('*', phrase('admin.md.js_italic')); break;
      case 'heading': prefixLines('## '); break;
      case 'list': prefixLines('- '); break;
      case 'link':
        var label = sel || phrase('admin.md.js_link');
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

// Updates tab: every translation is in the one form, so the language picker
// shows one at a time instead of loading a page. Without JavaScript they're a
// list of collapsible rows instead.
document.querySelectorAll('[data-lang-switch]').forEach(function (picker) {
  var box = picker.closest('details.translations');
  var items = Array.prototype.slice.call(box.querySelectorAll('details.translation[data-lang]'));
  var menu = picker.querySelector('details.lang-menu');
  var links = Array.prototype.slice.call(picker.querySelectorAll('.lang-menu-list a[data-lang]'));
  var next = picker.querySelector(':scope > a[data-lang]');
  var arrow = document.documentElement.dir === 'rtl' ? ' \u2190' : ' \u2192';
  function show(code) {
    items.forEach(function (item) {
      item.hidden = item.dataset.lang !== code;
      item.open = true;
    });
    links.forEach(function (a) {
      if (a.dataset.lang === code) {
        a.setAttribute('aria-current', 'page');
        picker.querySelector('[data-current]').textContent = a.firstElementChild.textContent;
        picker.querySelector('[data-current]').lang = code;
        var status = picker.querySelector('[data-current-status]');
        status.textContent = a.lastElementChild.textContent;
        status.className = a.lastElementChild.className;
      } else {
        a.removeAttribute('aria-current');
      }
    });
    // "Next untranslated": the next one after this that hasn't got a version.
    if (next) {
      var at = links.findIndex(function (a) { return a.dataset.lang === code; });
      var order = links.slice(at + 1).concat(links.slice(0, at));
      var todo = order.filter(function (a) { return a.lastElementChild.classList.contains('todo'); })[0];
      next.hidden = !todo;
      if (todo) { next.dataset.lang = todo.dataset.lang; next.href = todo.getAttribute('href'); }
    }
    menu.open = false;
  }
  picker.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-lang]');
    if (!a) return;
    e.preventDefault();
    show(a.dataset.lang);
    var field = box.querySelector('details.translation[data-lang="' + a.dataset.lang + '"] input');
    if (field) field.focus();
  });
  box.dataset.switching = '';
  picker.hidden = false;
  show(links[0].dataset.lang);
});
