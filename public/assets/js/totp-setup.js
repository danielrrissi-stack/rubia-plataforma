(function () {
  var el = document.getElementById('totp-qr');
  if (!el || typeof QRCode === 'undefined') { return; }
  new QRCode(el, {
    text: el.getAttribute('data-otpauth'),
    width: 192,
    height: 192,
    correctLevel: QRCode.CorrectLevel.M
  });
})();
