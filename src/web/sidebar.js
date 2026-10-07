(function (config) {
  if (window.betterCacheSidebar) {
    return;
  }
  window.betterCacheSidebar = true;

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[' + config.urlAttribute + ']');
    if (!button || button.classList.contains('loading')) {
      return;
    }
    event.preventDefault();
    button.classList.add('loading');
    Craft.sendActionRequest('POST', config.action, {data: {url: button.getAttribute(config.urlAttribute)}})
      .then(function (response) {
        Craft.cp.displaySuccess(response.data.message);
        var card = button.closest('fieldset');
        card.querySelector('[' + config.statusAttribute + ']').textContent = config.clearedStatus;
        button.remove();
      })
      .catch(function (error) {
        Craft.cp.displayError(error && error.response && error.response.data ? error.response.data.message : null);
      })
      .finally(function () {
        button.classList.remove('loading');
      });
  });
})
