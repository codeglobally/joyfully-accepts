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
