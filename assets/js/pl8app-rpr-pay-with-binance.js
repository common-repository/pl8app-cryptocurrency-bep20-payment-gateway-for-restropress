jQuery(function($) {
  if (typeof window.BinanceChain !== "undefined") {
    const btn = $(".Pay-binance-button"); // The pay with binance button

    let selectedAddress;

    function showDialogStep(index) {
      $(".dialog-step").hide();
      $(`.dialog-step-${index}`).show();
    }

    $("#dialog").dialog({
      autoOpen: false,
      close: function(event, ui) {
        btn.removeClass("disabled");
      }
    });

    $(".dialog-step input[type=submit][value=Close]").click(function() {
      $("#dialog").dialog("close");
    });

    btn.click(function() {
      if ($(this).hasClass("disabled")) {
        return;
      }

      $(this).addClass("disabled");
      showDialogStep(1);
      $("#dialog").dialog("open");

      window.BinanceChain.requestAccounts().then(accounts => {
        $(".account-list").empty();

        for (const account of accounts) {
          const listItem = $("<li />");
          const inputElem = $(
            `<input type="radio" id="${account.id}" name="account" value="${account.id}">`
          );

          inputElem.on("change", function() {
            selectedAddress = accounts.find(
              account => account.id === $(this).val()
            );
            $(".dialog-step-2 input[type=submit][value=Next]").removeClass(
              "disabled"
            );
          });

          listItem.append(inputElem);
          listItem.append(
            $(`<label for="${account.id}">${account.name}</label>`)
          );

          $(".account-list").append(listItem);
        }

        showDialogStep(2);
      });
    });

    $(".dialog-step-2 input[type=submit]").click(function() {
      showDialogStep(3);

      const storeWalletAddress = $("span.storewalletaddress")
        .data("value")
        .toString()
        .trim();
      const contractaddress = $("span.tokenaddress")
        .data("value")
        .toString()
        .trim();
      const amount = $("span.walletamount")
        .data("value")
        .toString()
        .trim();

      if (!storeWalletAddress)
        throw new Error("Error: Empty Store WalletAddress!");
      if (!contractaddress)
        throw new Error("Error: Empty Token Contract Address!");
      if (!amount) throw new Error("Error: Empty Token Amount!");

      bnbLib
        .encodeABI(
          contractaddress,
          selectedAddress.addresses.find(address => address.type === "eth")
            .address,
          storeWalletAddress,
          amount
        )
        .then(result => {
          const transactionParameters = {
            chainId: "56",
            to: contractaddress, // Required except during contract publications.
            from: selectedAddress.addresses.find(
              address => address.type === "eth"
            ).address, // must match user's active address.
            gasPrice: `0x${result.gasPrice}`,
            gas: `0x${result.gas}`,
            data: result.data,
            value: "0x0"
          };

          return window.BinanceChain.request({
            method: "eth_sendTransaction",
            params: [transactionParameters]
          });
        })
        .then(transactionHash => {
          $(".dialog-step-4 .transaction-hash").attr(
            "href",
            `https://www.bscscan.com/tx/${transactionHash}`
          );
          $(".dialog-step-4 .transaction-hash").text(transactionHash);
          showDialogStep(4);
        })
        .catch(e => {
          $(".dialog-step-5 .transaction-error").text(e.error || e.message);
          showDialogStep(5);
        });
    });

    btn.show();
  }
});
