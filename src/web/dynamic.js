(function (config) {

  function fetchIncludes() {
    var placeholders = document.querySelectorAll('[' + config.includeAttribute + ']');
    return Promise.all(Array.prototype.map.call(placeholders, function (placeholder) {
      var url = new URL(config.includeUrl, window.location.href);
      url.searchParams.set(config.includeParam, placeholder.getAttribute(config.includeAttribute));
      return fetch(url, {credentials: 'same-origin'})
        .then(function (response) { return response.ok ? response.text() : ''; })
        .then(function (html) { placeholder.outerHTML = html; });
    }));
  }

  function fillCsrfTokens() {
    var fields = document.querySelectorAll('[' + config.csrfAttribute + ']');
    if (!fields.length) {
      return;
    }
    fetch(config.csrfUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (response) { return response.json(); })
      .then(function (data) {
        fields.forEach(function (field) {
          field.setAttribute(field.getAttribute(config.csrfAttribute), data.token);
          field.removeAttribute(config.csrfAttribute);
        });
      });
  }

  function run() {
    fetchIncludes().then(fillCsrfTokens);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})
