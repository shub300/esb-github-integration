<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.19.5/jquery.validate.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/3.3.2/jquery-confirm.min.js"></script>

<script>
    // function showOverlay(){
    //     $.LoadingOverlay("show", {
    //         image       : "",
    //         background: "rgba(0, 0, 0, 0.5)",
    //         // background      : "rgba(0, 0, 0, 0.5)",
    //         text        : "Loading..."
    //         //fontawesome : "fa fa-cog fa-spin fa-loading",
    //         fontawesome : "fas fa-spinner",
    //         fontawesomeAnimation: "1.5s fadein",
    //         fontawesomeAutoResize: true,
    //         fontawesomeColor:"#ffcc00",
    //         fontawesomeResizeFactor:3
    //     });
    // }
    // function hideOverlay(){
    //     $.LoadingOverlay("hide");
    // }
    function successNotify(msg,head=''){
        toastr.success(msg, head, {timeOut: 5000});
    }
    function errorNotify(msg,head=''){
        toastr.error(msg, head, {timeOut: 5000});
    }
    function warningNotify(msg,head=''){
        toastr.warning(msg, head, {timeOut: 5000});
    }

    function makeAjax(method,url,sdata){
        blockUI();
        $.ajax({
                type:method,
                //headers: {'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')},
                url: url,
                data: sdata,
                // async:false,
                dataType: "json",
                success: function(res) {
                  unblockUI();
                  if(res.status_code==1){
                      successNotify(res.status_text,'Success');
                      return res;
                  }else if(res.status_code==0){
                      errorNotify(res.status_text,'Failed');
                      return res;
                  }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    unblockUI();
                    if (jqXHR.status == 500) {
                      errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
                    } else {
                      errorNotify('Unexpected error Please try again.','Failed');
                    }
                }
          });
    }

    function NewWindow(auth_url){
        window.open(auth_url,"popup","width=600,height=600,scrollbars=no,resizable=no");
    }
</script>

<script type="text/javascript">
    // Function to set profile text when profile picture is not available
    $(document).ready(function(){
        var initials = $('#user_first_name').text().charAt(0);
        $('.img-circle').text(initials);
    });
</script>

<script src="{{ asset('public/js/pages/amazon_cognito_jwt_token_verify.js') }}"></script>