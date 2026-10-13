// Passkeys for admin sign-in (see private/passkeys.php).
// Buttons with data-passkey="login", "confirm" or "register" ask the server
// for a challenge, let the device sign it (Face ID, fingerprint, PIN), then
// send the answer back by submitting the button's form.
(function () {
  var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-passkey]'));
  if (!buttons.length) return;
  var supported = !!(window.PublicKeyCredential && navigator.credentials && window.fetch);
  document.querySelectorAll('[data-passkey-unsupported]').forEach(function (el) { el.hidden = supported; });
  if (!supported) return;

  function toBytes(text) {
    var s = atob(text.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((text.length + 3) % 4));
    var bytes = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) bytes[i] = s.charCodeAt(i);
    return bytes.buffer;
  }
  function toText(buffer) {
    var bytes = new Uint8Array(buffer), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function field(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  buttons.forEach(function (button) {
    var form = button.form;
    var message = form.querySelector('[data-passkey-message]');
    function say(text) {
      if (!message) return;
      message.textContent = text;
      message.hidden = !text;
    }
    button.hidden = false;
    button.addEventListener('click', function () {
      var purpose = button.dataset.passkey;
      var body = new FormData();
      body.append('csrf', form.elements.csrf.value);
      body.append('purpose', purpose);
      button.disabled = true;
      say('');
      fetch('/admin.php?passkey_options=1', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (response) {
          return response.json().then(function (options) {
            if (!response.ok) throw new Error(options.error || 'Please reload the page and try again.');
            return options;
          });
        })
        .then(function (options) {
          options.challenge = toBytes(options.challenge);
          if (purpose === 'register') {
            options.user.id = toBytes(options.user.id);
            options.excludeCredentials.forEach(function (c) { c.id = toBytes(c.id); });
            return navigator.credentials.create({ publicKey: options });
          }
          options.allowCredentials.forEach(function (c) { c.id = toBytes(c.id); });
          return navigator.credentials.get({ publicKey: options });
        })
        .then(function (credential) {
          var r = credential.response;
          field(form, 'action', 'passkey_' + purpose);
          field(form, 'credential_id', toText(credential.rawId));
          field(form, 'client_data', toText(r.clientDataJSON));
          if (purpose === 'register') {
            field(form, 'attestation', toText(r.attestationObject));
          } else {
            field(form, 'authenticator_data', toText(r.authenticatorData));
            field(form, 'signature', toText(r.signature));
          }
          form.submit();
        })
        .catch(function (error) {
          button.disabled = false;
          if (error && error.name === 'InvalidStateError') {
            say('That device already has a passkey for this site.');
          } else if (error && error.name === 'NotAllowedError') {
            say(purpose === 'register' ? 'No passkey was added. You can try again.' : 'Cancelled, or no passkey was found on this device. You can try again.');
          } else {
            say(error && error.message ? error.message : 'Something went wrong. Please try again.');
          }
        });
    });
  });
})();
