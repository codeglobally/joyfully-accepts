// Phrases in the page's language, from js_phrases() in private/i18n.php.
var PHRASES = (function () {
  var el = document.getElementById('phrases');
  try { return el ? JSON.parse(el.dataset.phrases) : {}; } catch (e) { return {}; }
})();
function phrase(key, text) {
  return (PHRASES[key] || key).replace('{text}', text || '');
}

// Turns each dietary multi-select into a box of removable chips with a
// dropdown of choices. Typing filters the list; Enter (or a comma) adds
// what's typed. The hidden <select> stays the source of truth, so the form
// submits exactly as it would without this script.
(function () {
  document.querySelectorAll('select.diet-select').forEach(enhance);

  function enhance(select) {
    var placeholder = select.dataset.placeholder || '';
    var listId = select.id + '-list';
    var wrap = el('div', 'chips-wrap');
    var box = el('div', 'chips');
    var input = el('input', 'chips-input');
    var list = el('ul', 'chips-list');
    var active = -1;
    var quiet = false;   // focus without opening the list (after removing a chip)

    // Hand the select's id to the new input so its <label> focuses it.
    input.id = select.id;
    select.id = select.id + '-native';
    select.hidden = true;
    select.tabIndex = -1;

    input.type = 'text';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    box.appendChild(input);
    wrap.appendChild(box);
    wrap.appendChild(list);
    select.parentNode.insertBefore(wrap, select.nextSibling);
    renderChips();

    // Clicking empty space in the box focuses the text input.
    box.addEventListener('mousedown', function (e) {
      if (e.target === box) {
        e.preventDefault();
        input.focus();
      }
    });
    // Keep focus in the input while clicking a choice.
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });
    input.addEventListener('focus', function () {
      if (!quiet) {
        renderList();
      }
      quiet = false;
    });
    input.addEventListener('click', renderList);
    // While typing, highlight the first match so Enter picks it; a custom
    // entry is only added when nothing matches (or by choosing "Add …").
    input.addEventListener('input', function () {
      active = input.value.trim() ? 0 : -1;
      renderList();
    });
    input.addEventListener('blur', close);
    input.addEventListener('keydown', onKey);

    function options() {
      return Array.prototype.slice.call(select.options);
    }

    function find(text) {
      var lower = text.toLowerCase();
      return options().filter(function (o) { return o.text.toLowerCase() === lower; })[0];
    }

    function renderList() {
      var query = input.value.trim();
      var lower = query.toLowerCase();
      var items = options()
        .filter(function (o) { return !o.selected && o.text.toLowerCase().indexOf(lower) !== -1; })
        .map(function (o) { return { value: o.text, label: o.text }; });
      if (query && !find(query)) {
        items.push({ value: query, label: phrase('chips.add', query), add: true });
      }

      list.textContent = '';
      if (!items.length) {
        close();
        return;
      }
      items.forEach(function (item, i) {
        var li = el('li', item.add ? 'add' : '');
        li.id = listId + '-' + i;
        li.setAttribute('role', 'option');
        li.textContent = item.label;
        li.dataset.value = item.value;
        li.addEventListener('click', function () { choose(item.value); });
        list.appendChild(li);
      });
      active = Math.min(active, items.length - 1);
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      highlight();
    }

    function close() {
      list.hidden = true;
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function highlight() {
      var items = list.querySelectorAll('li');
      for (var i = 0; i < items.length; i++) {
        var on = i === active;
        items[i].classList.toggle('active', on);
        items[i].setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) {
          input.setAttribute('aria-activedescendant', items[i].id);
          items[i].scrollIntoView({ block: 'nearest' });
        }
      }
      if (active < 0) {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function choose(text) {
      text = text.replace(/[\s,]+/g, ' ').trim().slice(0, 60);
      if (!text) {
        return;
      }
      var option = find(text);
      if (!option) {
        option = new Option(text, text);
        select.add(option);
      }
      option.selected = true;
      input.value = '';
      active = -1;
      renderChips();
      renderList();
    }

    function remove(option) {
      option.selected = false;
      renderChips();
      close();
      quiet = document.activeElement !== input;
      input.focus();
    }

    function renderChips() {
      var old = box.querySelectorAll('.chip');
      for (var i = 0; i < old.length; i++) {
        box.removeChild(old[i]);
      }
      var picked = options().filter(function (o) { return o.selected; });
      picked.forEach(function (option) {
        var chip = el('span', 'chip');
        var x = el('button', 'chip-x');
        chip.appendChild(document.createTextNode(option.text));
        x.type = 'button';
        x.textContent = '×';
        x.setAttribute('aria-label', phrase('chips.remove', option.text));
        x.addEventListener('click', function () { remove(option); });
        chip.appendChild(x);
        box.insertBefore(chip, input);
      });
      input.placeholder = picked.length ? '' : placeholder;
    }

    function onKey(e) {
      var count = list.querySelectorAll('li').length;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (list.hidden) {
          renderList();
        }
        active = Math.min(active + 1, list.querySelectorAll('li').length - 1);
        highlight();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(active - 1, 0);
        highlight();
      } else if (e.key === 'Enter' || e.key === ',') {
        // Only intercept when there's something to add; otherwise Enter submits as usual.
        if (active >= 0 && count) {
          e.preventDefault();
          choose(list.querySelectorAll('li')[active].dataset.value);
        } else if (input.value.trim()) {
          e.preventDefault();
          choose(input.value);
        } else if (e.key === ',') {
          e.preventDefault();
        }
      } else if (e.key === 'Backspace' && input.value === '') {
        var picked = options().filter(function (o) { return o.selected; });
        if (picked.length) {
          remove(picked[picked.length - 1]);
        }
      } else if (e.key === 'Escape') {
        close();
      }
    }
  }

  function el(tag, className) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    return node;
  }
})();

// "Who's coming?": only show dietary pickers for the people ticked.
(function () {
  var boxes = document.querySelectorAll('.coming input[type="checkbox"][data-person]');
  function sync() {
    boxes.forEach(function (box) {
      var row = document.querySelector('.diet-row[data-person="' + box.dataset.person + '"]');
      if (row) row.hidden = !box.checked;
    });
  }
  boxes.forEach(function (box) { box.addEventListener('change', sync); });
  sync();
})();

// Collapsible cards: the double-chevron in each heading opens and closes the
// card, and each guest's choices are remembered in this browser.
(function () {
  var STORE = 'wedding-collapsed-cards';
  var collapsed = [];
  try { collapsed = JSON.parse(localStorage.getItem(STORE) || '[]') || []; } catch (e) { collapsed = []; }

  function save() {
    try { localStorage.setItem(STORE, JSON.stringify(collapsed)); } catch (e) { /* private mode etc. */ }
  }

  function set(card, open) {
    var btn = card.querySelector('.card-toggle');
    var body = card.querySelector('.card-body');
    if (!btn || !body) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('title', phrase(open ? 'card.collapse' : 'card.expand'));
    body.hidden = !open;
    card.classList.toggle('is-collapsed', !open);
  }

  document.querySelectorAll('.card.collapsible').forEach(function (card) {
    var key = card.dataset.key;
    // Always open: RSVP until they've replied (data-open), and any card with a
    // message or error after replying, even if they'd closed it before.
    var forceOpen = card.dataset.open === '1' || card.querySelector('.notice, .error');
    set(card, forceOpen || collapsed.indexOf(key) === -1);
    card.querySelector('.card-toggle').addEventListener('click', function () {
      var open = this.getAttribute('aria-expanded') !== 'true';
      set(card, open);
      collapsed = collapsed.filter(function (k) { return k !== key; });
      if (!open) collapsed.push(key);
      save();
    });
  });
})();
