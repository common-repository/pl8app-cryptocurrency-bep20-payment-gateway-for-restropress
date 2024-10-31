jQuery(function($) {
  $(document.body).on("click", "#create_new_wallet", function() {
    var $new_row =
      '<li><input type="text" name="pl8app_rpr_pro_redux_options[pl8app_rpr_addresses][]" value="" class="regular-text">' +
      '<a href="javascript:void(0);" class="deletion">Remove</a></li>';

    $("#pl8app_rpr_addresses-ul").append($new_row);
  });

  $(document.body).on("click", "a.deletion", function() {
    $(this)
      .closest("li")
      .remove();
  });
});
