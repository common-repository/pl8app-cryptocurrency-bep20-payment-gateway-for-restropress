jQuery(function($) {
  if (typeof window.ethereum !== "undefined") {
    const btn = $(".Pay-metamask-button"); // The pay with MetaMask button

    btn.click(async function() {
      var storeWalletAddress = $("span.storewalletaddress")
        .data("value")
        .toString()
        .trim();
      var contractaddress = $("span.tokenaddress")
        .data("value")
        .toString()
        .trim();
      var amount = $("span.walletamount")
        .data("value")
        .toString()
        .trim();

      try {
        await ethereum.request({
          method: "wallet_switchEthereumChain",
          params: [{ chainId: "0x38" }]
        });
      } catch (switchError) {
        // This error code indicates that the chain has not been added to MetaMask.
        if (switchError.code === 4902) {
          try {
            await ethereum.request({
              method: "wallet_addEthereumChain",
              params: [
                {
                  chainId: "0x38",
                  chainName: "Binance Smart Chain",
                  nativeCurrency: {
                    name: "Binance Coin",
                    symbol: "BNB",
                    decimals: 18
                  },
                  rpcUrls: ["https://bsc-dataseed.binance.org/"],
                  blockExplorerUrls: ["https://bscscan.com"]
                }
              ]
            });
          } catch (addError) {
            alert(
              "Can't change network to Binance Smart chain automatically, Please change network Manually!"
            );
          }
        }
        // handle other "switch" errors
        alert(switchError.message);
      }

      try {
        const accounts = await ethereum.request({
          method: "eth_requestAccounts"
        });
      } catch (e) {
        if (e.message == "ethereum is not defined") {
          alert("Please install MetaMask Chrome extension in your browser!");
        } else {
          alert("Error: " + e.message);
        }

        return;
      }

      try {
        if (!storeWalletAddress)
          throw new Error("Error: Empty Store WalletAddress!");
        if (!contractaddress)
          throw new Error("Error: Empty Token Contract Address!");
        if (!amount) throw new Error("Error: Empty Token Amount!");

        const result = await bnbLib.encodeABI(
          contractaddress,
          ethereum.selectedAddress,
          storeWalletAddress,
          amount
        );

        if (!result.data || !result.gas || !result.gasPrice)
          throw new Error("Error: Undefined Data!");

        const transactionParameters = {
          to: contractaddress, // Required except during contract publications.
          from: ethereum.selectedAddress, // must match user's active address.
          chainId: "56",
          gasPrice: result.gasPrice,
          gas: result.gas,
          data: result.data
        };

        const transactionHash = await ethereum.request({
          method: "eth_sendTransaction",
          params: [transactionParameters]
        });
        // Handle the result
        alert("Transaction is success!");
      } catch (error) {
        alert("Error: " + error.message);
      }
    });

    btn.show();
  }
});
