/* global mirloCatalog */
/*
 * [mirlo_catalog]: format prices for the visitor's locale, and start Mirlo's
 * hosted checkout when someone presses Buy or Subscribe.
 */
(function () {
  function formatPrices() {
    document
      .querySelectorAll(".mirlo-catalog__price[data-amount]")
      .forEach(function (el) {
        try {
          el.textContent =
            new Intl.NumberFormat(undefined, {
              style: "currency",
              currency: el.dataset.currency,
            }).format(Number(el.dataset.amount) / 100) +
            (el.dataset.suffix || "");
        } catch (e) {
          // Keep the server's version.
        }
      });
  }

  function showError(button, message) {
    var error = button.parentNode.querySelector(".mirlo-catalog__error");
    if (error) {
      error.textContent = message;
      error.hidden = false;
    }
  }

  function buy(button) {
    var catalog = button.closest(".mirlo-catalog");
    var data = new URLSearchParams({
      nonce: mirloCatalog.nonce,
      success_url: catalog.dataset.successUrl || "",
    });
    if (button.dataset.mirloBuy === "merch") {
      data.set("action", "mirlo_purchase_merch");
      data.set("artist", catalog.dataset.artist);
      data.set("merch_id", button.dataset.merchId);
    } else {
      data.set("action", "mirlo_purchase_v2");
      data.set("artist_id", button.dataset.artistId);
      data.set("tier_id", button.dataset.tierId);
    }

    var label = button.textContent;
    button.disabled = true;
    button.textContent = "Redirecting…";
    var error = button.parentNode.querySelector(".mirlo-catalog__error");
    if (error) {
      error.hidden = true;
    }

    fetch(mirloCatalog.ajax_url, {
      method: "POST",
      credentials: "same-origin",
      body: data,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (response.success && response.data.redirectUrl) {
          window.location.href = response.data.redirectUrl;
          return;
        }
        var d = response.data || {};
        throw new Error(
          (d.message || "Couldn't start the checkout.") +
            (d.detail ? " (" + d.detail + ")" : ""),
        );
      })
      .catch(function (e) {
        button.disabled = false;
        button.textContent = label;
        showError(
          button,
          e instanceof SyntaxError || e instanceof TypeError
            ? "Network error — please try again."
            : e.message,
        );
      });
  }

  document.addEventListener("click", function (e) {
    var button = e.target.closest(".mirlo-catalog [data-mirlo-buy]");
    if (button && !button.disabled) {
      buy(button);
    }
  });

  // Coming back with the browser's Back button can restore the page as it
  // was left, with the button still saying "Redirecting…".
  window.addEventListener("pageshow", function (e) {
    if (!e.persisted) {
      return;
    }
    document
      .querySelectorAll(".mirlo-catalog [data-mirlo-buy][disabled]")
      .forEach(function (button) {
        button.disabled = false;
        button.textContent =
          button.dataset.mirloBuy === "merch" ? "Buy" : "Subscribe";
      });
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", formatPrices);
  } else {
    formatPrices();
  }
})();
