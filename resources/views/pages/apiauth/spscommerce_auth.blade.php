@extends('layouts.master')

@section('head-content')

<style>

</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
@endsection

@section('title', 'Authenticate Account')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Authenticate Account</li>
@endsection

@push('page-style')

<style>
  .auth-wrapper {
    display: -webkit-box;
    display: -webkit-flex;
    display: -ms-flexbox;
    display: flex;
    -webkit-flex-basis: 100%;
    -ms-flex-preferred-size: 100%;
    flex-basis: 100%;
    min-height: 100vh;
    min-height: calc(var(--vh, 1vh) * 100);
    width: 100%
  }

  .auth-wrapper .auth-inner {
    width: 100%
  }

  .auth-wrapper.auth-v1 {
    -webkit-box-align: center;
    -webkit-align-items: center;
    -ms-flex-align: center;
    align-items: center;
    -webkit-box-pack: center;
    -webkit-justify-content: center;
    -ms-flex-pack: center;
    justify-content: center;
    overflow: hidden
  }

  .auth-wrapper.auth-v1 .auth-inner {
    position: relative;
    max-width: 400px
  }

  .auth-wrapper.auth-v1 .auth-inner:before {
    width: 244px;
    height: 243px;
    content: ' ';
    position: absolute;
    top: -40px;
    left: -46px;
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPQAAADzCAMAAACG9Mt0AAAAAXNSR0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAA9KADAAQAAAABAAAA8wAAAADhQHfUAAAAyVBMVEUAAAD///+AgP+AgP9mZv+AgNWAgP9tbf9gYP+AgP9xcf9mZv+AZuaAgP9dXf90dOhiYv92dv9mZu5mZv93d+53d/9paf94afCAcfFrXvJra/9mZvJzZvJzc/JoaP96b/Rqav91aupsYvV2bOt2bPVxaPZ7cfZqavZyau1waPd4aO9xafBxafh4afB1bfh4avFuZ/F2afJzZvJzZ/N0aPN0bvN3bPR0ae5yZ/R3be93bfR1au9zafBxbPVzavV0a/F0a/ZyafFwaPKZm3nTAAAAQ3RSTlMAAQIEBQYGBwgICQoKCgsLDQ0PDw8PERESExMUFBQWFxgYGhoaGxsdHSAgIiIiIyQlJygqLCwtLi8vLzAzNDU3Nzg7h9vbHgAAA9RJREFUeNrt3ftS2kAUx/Fc1gSyWsErtuJdRDQiiteolb7/QzUoTm07k4AzObuu3/MCez45yWbzT36eZ6b8erO1e1B97baadd+zocJWmg0HaXe/+uqmg2GWtkLT5Lle1m9LdhG2+1lvzuiUO1knEF81yFc1N+35m15kZOGodz1vyLx+v2Lseq/erxtZd/NuweCTtfiwaWLOD5FnsqI7+VnP3y8afnEs3Es/1+H1qvETwuq18B7e6VlwLup1ZM8kWWQBOsrmHL7GVtxvYRZYgQ4ywae61ffsqH5Lbq20bQm6ncp9P2ehJegwE/u+rl95ttSwLrVSc2ANetAU28dSa9Cp2E623bUG3d2VWmn/wBq0XCugQYMGLdVKoOJaoiuok1NdXSW1WAUfRPtRUllflaJf5ZE/O9pXVbZUPTov5c+IDqvtRwStdTgLutoxy6GnGfYb2o+1I2gd+1OiqzfLocvVE7TSDqG1mgodaqfQZbvZC9rXjqG1X45WzqFVKVpk0LLo4lGP0ZGD6KgMnTiITkrQgXYQrYNitHISrYrRsZPouBhdcxJdK0YnTqKTYrR2Eq1BgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRoh9DH59ag86ACoSYOL61B55EUQk1s3VqDzsNHhJpYe7QGncfMSHUxaliCHgcKSXVxeWQJehwdJdXF4dAS9DgkTKqLxuibFeiXODixNi7OrEC/BP+JtbE0WrYA/RrxKNfH2YUF6NegSbk+Gk87xtErN6EsWm88fzeMXpwE9EruLns/l42io4dJFLPo2/Po1w+D6IW7t9Bt2SPx3vOOMfS7eHVZtN54ulg2go56138Ct4XRunE2Ovsmjg46WeddUoUWr6WL0fCoIYgO2/2s91fstDZQjcPL0ePt5flpdXUwqW46uMrS1j95JNpQrW0dHp9UV/uT2m416/8HVGg3qzhpBjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KC/FDpx0pwUo2tOomvF6NhJdFyMVk6iVTE6cBIdeF9vJyvZx/I/AzuIjsrQvoNovwzt4FamSs0Ojrp80PmvoB0zh940pb7azf1yg7t0LIt978uppzbnalfucDW92ZndLPRmKweGPduYJ+zoM5/Dk+gD5NdvLhXXPp88qcUqmEH5G5JZRs6cuxwIAAAAAElFTkSuQmCC)
  }

  .auth-wrapper.auth-v1 .auth-inner:after {
    width: 272px;
    height: 272px;
    content: ' ';
    position: absolute;
    bottom: -40px;
    right: -75px;
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAARAAAAEQCAMAAABP1NsnAAAAAXNSR0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAABEKADAAQAAAABAAABEAAAAAAQWxS2AAAAwFBMVEUAAAD///+AgICAgP9VVaqqVf+qqv+AgL+AgP9mZsxmZv+ZZv+AgNWAgP9tbdttbf+Sbf+AYN+AgN+AgP9xceNmZv+AZuaAZv90dOh0dP9qav+AauqAav+AgP92dv9tbf+Abe2Abf93Zu53d+6AcO94afCAcfF5a+R5a/JzZuaAZvKAc/J5bed5bfOAaPN6b/R1auqAavR6ZvV6cPV2bOuAbPV7aPZ2be2AbfZ7au17avZ3Zu53b+57a+97a/d4aO9J6CoeAAAAQHRSTlMAAQICAwMDBAQFBQUGBgcHBwgICAkKCgoLCwwMDAwNDg4ODw8QERITExQUFBUVFhcYGBkZGhobHBwdHR4eHx8gJ5uMWwAAA/FJREFUeNrt2G1XEkEYxvHZNk2xHGzdbKFl0cTwgdSkCKzu7/+t4pw6sAjtjIueE/f8r3fMO35nZnbuy5gVGcvfzJe0rnTfGI+MggGJRUZnbpPIhJKt88nU53JnFULvyISY6KAv8vPj0vr2rYwiE2Z2B9J+uNYcyyQxwWZvaeGH3G4bMjsvI/kcwTC/V+7kLoahlITzQojP3ZFgsJCh7IJQzpX0QFj4uMiY18eDMZ9bZCF9OQahnK6cm/Y7js0sh/LF3Auv1PlQd3MxbdXYIQspV44EEEAAAWTNDAYYkKdJbNMsLzYueZbaZ2iM46RVbHBaiZ9Js+nHEdli42N9XuSen5hGp1CQTuOJQDRsD99N4gMSpYWapNH6IJo83CIeILZQFesEaber79NCWRoukOpNEnW0gXQqD81w6ACxhbrYde7VuFCYeA2QRCNIsgZISyNIqz6IyhPjOjNVIFYniK3dmKU6QdLaJUimEySrDZLrBMlrgxRKU7sxCw/EMe0CAggggADySJCqxixIkKpNEh6IozELD8RxjQACCCCAAPJIkKrGLEgQXqqAAEJjxrQLCCCAAEJjRmNGY8a0CwgggABCYwYIfQgggNCYMe0CAggggNCY0ZjRmDHtAgIIIIAAQmNGHwIIIDRmTLuAAAIIIDRmNGY0Zky7gAACCCCA0JjRhwACCI0Z0y4ggAACCI0ZjRmNGdMuIIAAAgggNGb0IYAAQmPGtAsIIIAAQmNGY0ZjxrQLCCCAAAIIjRl9CCCA0Jgx7QICCCCA0JjRmNGYMe0CAggggABCY0YfAgggNGZMu4AAAgggNGY0ZjRmTLuAAAIIIIDQmNGHAAIIjRnTLiCAAAIIjRmNGY0ZIEy7gAACCCA0ZvQhgABCY8a0CwgggABCY0ZjBgiNGdMuIIAAAgiN2f/Sh+Q6PfLaIJlOkKw2SKoTJK3dmFmdILb2tBvrBIlrg5iWRo+WqQ+SaARJ1gCJAzsxThCN16p1vNurGjNjoo42j07kAHFskoY2kEbl33U0ZgoPjXW+Rl0gkarnahqtDaJKxMPDDWIiNafGenh4gExvVhXfmk7Da6L1AVGxSby2h6MxK79Zk42ea1pJbJ48sU2zDezQ8iy1z6BBwoyjMQsvXp8YQAAhgADilRfyy+wf8WqZZUfGZihvgZiB3FybC+kCUU5XLkAo50C+gbBQdUzkAIVyejIAYfFTI1solHP2HgNCnHn5AYNy4jvpoVB6fVzL91cwzLJ9Lfd7S0jhehxO5H5/yePr1W6gHonI7fJ5ORSR/n6Q2yQanq763zuXU5LJZRKiyD/W9/pjkdPZz0/yJ8fqVyry+qQZDMjJKoDfy8bRVhHhQTwAAAAASUVORK5CYII=);
    z-index: -1
  }

  @media (max-width:575.98px) {

    .auth-wrapper.auth-v1 .auth-inner:after,
    .auth-wrapper.auth-v1 .auth-inner:before {
      display: none
    }
  }

  .auth-wrapper.auth-v2 {
    -webkit-box-align: start;
    -webkit-align-items: flex-start;
    -ms-flex-align: start;
    align-items: flex-start
  }

  .auth-wrapper.auth-v2 .auth-inner {
    overflow-y: auto;
    height: calc(var(--vh, 1vh) * 100)
  }

  .auth-wrapper.auth-v2 .brand-logo {
    position: absolute;
    top: 2rem;
    left: 2rem;
    margin: 0;
    z-index: 1;
    -webkit-box-pack: unset;
    -webkit-justify-content: unset;
    -ms-flex-pack: unset;
    justify-content: unset
  }

  .auth-wrapper .brand-logo {
    display: -webkit-box;
    display: -webkit-flex;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-pack: center;
    -webkit-justify-content: center;
    -ms-flex-pack: center;
    justify-content: center;
    margin: 1rem 0 2rem
  }

  .auth-wrapper .brand-logo .brand-text {
    font-weight: 600
  }

  .auth-wrapper .auth-footer-btn .btn {
    padding: .6rem !important
  }

  .auth-wrapper .auth-footer-btn .btn:not(:last-child) {
    margin-right: 1rem
  }

  .auth-wrapper .auth-footer-btn .btn:focus {
    box-shadow: none
  }

  @media (min-width:1200px) {
    .auth-wrapper.auth-v2 .auth-card {
      width: 400px
    }
  }

  @media (max-width:575.98px) {
    .auth-wrapper.auth-v2 .brand-logo {
      left: 1.5rem;
      padding-left: 0
    }
  }

  .auth-wrapper .auth-bg {
    background-color: #FFF
  }

  .dark-layout .auth-wrapper .auth-bg {
    background-color: #283046
  }

  @media (max-height:625px) {
    .dark-layout .auth-wrapper .auth-inner {
      background-color: #283046
    }

    .auth-wrapper .auth-bg {
      padding-top: 3rem
    }

    .auth-wrapper .auth-inner {
      background-color: #FFF;
      padding-bottom: 1rem
    }

    .auth-wrapper.auth-v2 .brand-logo {
      position: relative;
      left: 0;
      padding-left: 1.5rem
    }
  }


  .pace .pace-progress {
    height: 4px;
  }

  .header-navbar,
  .main-search-list-defaultlist,
  .main-menu,
  .content-header-left,
  .footer,
  .header-navbar-shadow,
  .drag-target {
    display: none !important;
  }

  .app-content {
    width: 100% !important;
    margin: 0 !important;
    padding-top: 0 !important;
  }

  @media(max-width:767px) {
    html body .app-content {
      padding: 0 !important;
    }
  }
</style>

@endpush

@section('page-content')


<div class="auth-wrapper auth-v1 px-2">
  <div class="auth-inner py-2">
    <!-- Register v1 -->
    <div class="card mb-0">
      <div class="card-body">
        <a href="#" class="brand-logo">


        <img class="icon" src="{{env('CONTENT_SERVER_PATH')}}{{'/public/esb_asset/brand_icons/spscom.png'}}" width="30%" height="30%">

        </a>

        <h4 class="card-title mb-1 text-center">Enter Authentication Details</h4>


        <form method="POST" action="{{url('/InitiateSpscomOauth')}}" onsubmit="return validateForm('spscommerce');">
          @csrf  
          <div class="mb-1 row" style="margin-left: 2px;">
                <label>Sandbox</label>&nbsp;<div class="custom-control custom-switch">
                    <input type="checkbox" class="custom-control-input" id="env_type" name="env_type" checked>
                    <label class="custom-control-label" for="env_type"></label>
                </div><!--<label>&nbsp;Production</label>-->
            </div>


            <div class="mb-1">
                <label class="my-label">Account Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="account_id" name="account_id" placeholder="Enter Account Name">
                <span class="field_error">@lang('tagscript.error_msg')</span>
            </div>

            <div class="mb-1">
                <label class="my-label">APP ID (Production Keys) <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="app_id" name="app_id" placeholder="Enter APP ID">
                <span class="field_error">@lang('tagscript.error_msg')</span>
            </div>

            <div class="mb-1">
                <label class="my-label">APP SECRET (Production Keys) <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="app_secret" name="app_secret" placeholder="Enter APP SECRET">
                <span class="field_error">@lang('tagscript.error_msg')</span>
            </div>



          <div class="mb-1">

            <label class="my-label">Redirect URI (For App) <span class="text-danger">*</span></label>
            <div class="input-group mb-3">
              <input type="text" class="form-control" id='input-text' readonly value="{{ url('/RedirectHandlerSpsCom') }}" aria-describedby="basic-addon2">
              <div class="input-group-append">
                <button class="btn btn-secondary" id="CopytoClip" onClick="copyToClipboard()" type="button"><i class="fa fa-copy" aria-hidden="true"></i> Copy</button>
              </div>
            </div>


          </div>


          <div class="row justify-content-center pb-1">
            <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
              {{-- <button type="submit" class="btn btn-success waves-effect waves-float waves-light " data-text='Connect Now' >
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                    </button> --}}
              <button type="submit" class="btn btn-primary waves-effect waves-float waves-light w-100" data-text='Connect Now'>
                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
              </button>
            </div>
          </div>
        </form>


      </div>
    </div>
    <!-- /Register v1 -->
  </div>
</div>



@endsection
@push('page-script')
<script>
  function validateForm(platform) {
    valid = true;
    $('.field_error').hide();

      if (!checkvalue($('#account_id'))) {
        showErrorAndFocus($('#account_id'));
        valid = false;
      }

      if (!checkvalue($('#app_id'))) {
        showErrorAndFocus($('#app_id'));
        valid = false;
      }

      if (!checkvalue($('#app_secret'))) {
        showErrorAndFocus($('#app_secret'));
        valid = false;
      }



    if (!valid) {
      return false;
    }
    var account_id = $('#account_id').val();
    var is_valid_account_name = validateAccountName(platform,account_id);

    if(!is_valid_account_name){
      return false;
    }
    return true;
  }

  function showErrorAndFocus(elem) {
    if (elem.length) {
      elem.next().show();
    }
  }

  function checkvalue(elem) {

    var input = elem.val();
    var regex = /(<([^>]+)>)/ig;
    var validatedInput = input.replace(regex, "");
    if(input != validatedInput){
        return false;
    }

    if (elem.length) {
      if (!elem.val().trim()) {
        return false;
      } else {
        return true;
      }
    }
  }


  function validateAccountName(platform,account_id) {

    var is_valid = false;


    	 $.ajax({
          async: false,
          type: 'POST',
          url: "{{url('/validateAccountName')}}",
          data: {
            '_token': $('meta[name="csrf-token"]').attr('content'),'platform':platform,'account_id':account_id
          },
          beforeSend: function() {
            showOverlay();
          },
          success: function(response) {
              hideOverlay();

              if (response.status_code === 1) {
                is_valid = true;
              }else{
                toastr.error(response.status_text);
              }

          },
          error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
              toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
              toastr.error('Unexpected error Please try again.');
            }
          }
       });

       return is_valid;

  }

  function copyToClipboard() {

    var inputElement=document.getElementById('input-text');
    inputElement.select();
    document.execCommand('copy');
    document.getElementById('CopytoClip').innerHTML = '<i class="fa fa-copy" aria-hidden="true"></i> Copied';

    // alert("Copied");

  }


$(function () {
      $('[data-toggle="tooltip"]').tooltip()
    })


</script>
@endpush
