$(function () {
	// colourise rows with status bg
    $(".fa-status-box").each(function () {
        var $this = $(this);
        var col = $this.css("color");
        var a = col.slice(4).split(",");
        var newAlpha = 0.1;
        var bgCol = "rgba(" + a[0] + "," + parseInt(a[1]) + "," + parseInt(a[2]) + "," + newAlpha + ")";
        $this.closest("tr").css("background-color", bgCol);
    });
});
