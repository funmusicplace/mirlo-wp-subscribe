/* global mirloCatalog */
/*
 * [mirlo_catalog]: format prices for the visitor's locale, start Mirlo's
 * hosted checkout when someone presses Buy or Subscribe, and send sales to
 * a card reader with "Pay on reader" (staff only).
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
    var cancel = e.target.closest(".mirlo-catalog__reader-cancel");
    if (cancel && !cancel.disabled) {
      cancelReaderSale(cancel.closest(".mirlo-catalog__reader-form"));
    }
  });

  /* ------------------------------------------------------------------
   * Card reader sales: send the sale to the chosen reader, then check
   * every couple of seconds until the card is tapped, it's cancelled, or
   * we give up waiting.
   * ------------------------------------------------------------------ */

  var POLL_MS = 2000;

  function post(params) {
    return fetch(mirloCatalog.ajax_url, {
      method: "POST",
      credentials: "same-origin",
      body: params,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (response) {
        if (response.success) {
          return response.data;
        }
        var d = response.data || {};
        throw new Error(
          (d.message || "Something went wrong.") +
            (d.detail ? " (" + d.detail + ")" : ""),
        );
      })
      .catch(function (e) {
        throw e instanceof SyntaxError || e instanceof TypeError
          ? new Error("Network error — please try again.")
          : e;
      });
  }

  function readerSelect(form) {
    return form
      .closest(".mirlo-catalog")
      .querySelector(".mirlo-catalog__reader-select");
  }

  function readerStorageKey(form) {
    var bar = form
      .closest(".mirlo-catalog")
      .querySelector(".mirlo-catalog__reader");
    return "mirloReader:" + (bar ? bar.dataset.artistId : "");
  }

  function setReaderStatus(form, text, state) {
    var status = form.querySelector(".mirlo-catalog__reader-status");
    status.textContent = text;
    status.hidden = !text;
    status.dataset.state = state || "";
  }

  // Back to ready for the next sale. The last status stays visible.
  function finishReaderSale(form, text, state) {
    form._sale = null;
    setReaderStatus(form, text, state);
    form.querySelector(".mirlo-catalog__reader-cancel").hidden = true;
    var submit = form.querySelector('[type="submit"]');
    submit.disabled = false;
    submit.textContent = "Pay on reader";
  }

  function payOnReader(form) {
    var select = readerSelect(form);
    if (!select || !select.value) {
      setReaderStatus(form, "Choose a card reader first.", "error");
      return;
    }
    if (form.reportValidity && !form.reportValidity()) {
      return;
    }

    var catalog = form.closest(".mirlo-catalog");
    var data = new URLSearchParams(new FormData(form));
    data.set("action", "mirlo_reader_charge");
    data.set("nonce", mirloCatalog.reader_nonce);
    data.set("artist", catalog.dataset.artist);
    data.set("reader_id", select.value);
    data.set("kind", form.dataset.mirloReader);
    if (form.dataset.merchId) {
      data.set("merch_id", form.dataset.merchId);
    }
    if (form.dataset.tierId) {
      data.set("tier_id", form.dataset.tierId);
    }

    var readerName = select.options[select.selectedIndex].text.replace(
      / \(offline\)$/,
      "",
    );
    var submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    submit.textContent = "Sending…";
    setReaderStatus(form, "");

    post(data)
      .then(function (d) {
        var sale = {
          intentId: d.intentId,
          accountId: d.accountId,
          readerId: select.value,
          readerName: readerName,
          giveUpAt: Date.now() + (d.timeout || 180) * 1000,
        };
        form._sale = sale;
        submit.textContent = "Waiting…";
        form.querySelector(".mirlo-catalog__reader-cancel").hidden = false;
        setReaderStatus(
          form,
          "Waiting for the card on " + readerName + "…",
          "waiting",
        );
        pollReaderSale(form, sale);
      })
      .catch(function (e) {
        finishReaderSale(form, e.message, "error");
      });
  }

  function pollReaderSale(form, sale) {
    setTimeout(function () {
      // Cancelled, or another sale started, in the meantime.
      if (form._sale !== sale) {
        return;
      }
      post(
        new URLSearchParams({
          action: "mirlo_reader_status",
          nonce: mirloCatalog.reader_nonce,
          intent_id: sale.intentId,
          account_id: sale.accountId,
        }),
      )
        .then(function (d) {
          if (form._sale !== sale) {
            return;
          }
          if (d.status === "succeeded") {
            var email = form.querySelector('[name="email"]');
            if (email) {
              email.value = "";
            }
            finishReaderSale(
              form,
              form.dataset.mirloReader === "tier"
                ? "Subscribed. Thank you!"
                : "Paid. Thank you!",
              "paid",
            );
            return;
          }
          if (d.status === "canceled") {
            finishReaderSale(form, "Cancelled.", "cancelled");
            return;
          }
          setReaderStatus(
            form,
            d.status === "requires_capture" || d.status === "processing"
              ? "Card read, approving…"
              : "Waiting for the card on " + sale.readerName + "…",
            "waiting",
          );
          keepPolling(form, sale);
        })
        .catch(function () {
          // A dropped request: try again until it's time to give up.
          keepPolling(form, sale);
        });
    }, POLL_MS);
  }

  function keepPolling(form, sale) {
    if (Date.now() < sale.giveUpAt) {
      pollReaderSale(form, sale);
      return;
    }
    // Stop checking, but leave Cancel so it can be cleared off the reader.
    setReaderStatus(
      form,
      "No payment yet. Check the reader, or cancel and try again.",
      "error",
    );
    var submit = form.querySelector('[type="submit"]');
    submit.disabled = false;
    submit.textContent = "Pay on reader";
  }

  function cancelReaderSale(form) {
    var sale = form._sale;
    if (!sale) {
      return;
    }
    var cancel = form.querySelector(".mirlo-catalog__reader-cancel");
    cancel.disabled = true;
    post(
      new URLSearchParams({
        action: "mirlo_reader_cancel",
        nonce: mirloCatalog.reader_nonce,
        intent_id: sale.intentId,
        account_id: sale.accountId,
        reader_id: sale.readerId,
      }),
    )
      .then(function () {
        finishReaderSale(form, "Cancelled.", "cancelled");
      })
      .catch(function (e) {
        setReaderStatus(form, e.message, "error");
      })
      .then(function () {
        cancel.disabled = false;
      });
  }

  document.addEventListener("submit", function (e) {
    var form = e.target.closest(".mirlo-catalog__reader-form");
    if (!form) {
      return;
    }
    e.preventDefault();
    // Still waiting on the last one: cancel that first.
    if (form._sale && Date.now() < form._sale.giveUpAt) {
      return;
    }
    payOnReader(form);
  });

  // Remember the chosen reader on this device.
  document.addEventListener("change", function (e) {
    if (!e.target.matches(".mirlo-catalog__reader-select")) {
      return;
    }
    try {
      localStorage.setItem(readerStorageKey(e.target), e.target.value);
    } catch (err) {
      // Private browsing: just don't remember it.
    }
  });

  function restoreReaders() {
    document
      .querySelectorAll(".mirlo-catalog__reader-select")
      .forEach(function (select) {
        try {
          var saved = localStorage.getItem(readerStorageKey(select));
          if (
            saved &&
            Array.prototype.some.call(select.options, function (o) {
              return o.value === saved;
            })
          ) {
            select.value = saved;
          }
        } catch (err) {
          // Keep the first reader.
        }
      });
  }

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

  function ready() {
    formatPrices();
    restoreReaders();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready);
  } else {
    ready();
  }
})();
