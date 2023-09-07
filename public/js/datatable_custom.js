var csrf_token = $('meta[name="csrf-token"]').attr("content");
function queryParams(params) {
    params._token = csrf_token;
    params.product_type = $(".stuller_type").val();
    return params;
}
