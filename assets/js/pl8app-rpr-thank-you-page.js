jQuery(function($) {
  $(".clipboard").click(function() {
    var $temp = $("<input>");
    $("body").append($temp);
    $temp
      .val(
        $(this)
          .attr("data-value")
          .trim()
      )
      .select();
    document.execCommand("copy");
    $temp.remove();
    $("<span class='tooltip'>Copied to Clipboard!</span>").insertAfter(this);
    setTimeout(function() {
      $(".tooltip").remove();
    }, 1000);
  });
});
