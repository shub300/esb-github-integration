@php
    //use App\Http\Controllers\CommonController;
    //$is_set = CommonController::checkTermsAndPolicies();
@endphp
{{-- {{ config('org_details.name')?config('org_details.name'):'ESB' }} --}}
<footer class="footer footer-static footer-light">
    <input type="hidden" value="{{url('/')}}" id="iconPath">
    @if(!isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')]))
    <p class="clearfix mb-0 text-center"><span class="d-block mt-25">Copyright &copy; {{date('Y')}}<a class="ml-25" href="https://apiworx.com/" target="_blank"><b>Apiworx</b></a><span class="d-none d-sm-inline-block">&nbsp;|&nbsp;All rights reserved</span></span></p>
    @endif
    <p class="clearfix mb-0 text-center"><span class="d-block mt-25">
        @php 
            $pipe = (config('org_details.privacy_url') && config('org_details.terms_url')) ? '|' : '';
        @endphp
        @if(config('org_details.privacy_url')) <a class="ml-25" href="{{config('org_details.privacy_url')}}" target="_blank">Privacy</a>
        @endif {{$pipe}} 
        @if(config('org_details.terms_url')) <a  href="{{config('org_details.terms_url')}}" target="_blank">Terms</a>
        @endif
    </p>
</footer>
<button class="btn btn-primary btn-icon scroll-top" type="button"><i data-feather="arrow-up"></i></button>

  <!-- BEGIN: Vendor JS-->
  <script src="{{asset('public/esb_asset/vendors/js/vendors.min.js')}}"></script>
  <!-- BEGIN Vendor JS-->

  <!-- BEGIN: Page Vendor JS-->
  <script src="{{asset('public/esb_asset/vendors/js/charts/apexcharts.min.js')}}"></script>
  <script src="{{asset('public/esb_asset/vendors/js/extensions/toastr.min.js')}}"></script>
  <!-- END: Page Vendor JS-->

  <!-- BEGIN: Page Vendor JS-->
  <script src="{{asset('public/esb_asset/vendors/js/forms/select/select2.full.min.js')}}"></script>
  <!-- END: Page Vendor JS-->

  <!-- BEGIN: Theme JS-->
  <script src="{{asset('public/esb_asset/js/core/app-menu.min.js')}}"></script>
  <script src="{{asset('public/esb_asset/js/core/app.min.js')}}"></script>
  <!--script src="{{asset('public/esb_asset/js/scripts/customizer.min.js')}}"></script-->
  <!-- END: Theme JS-->

  <!-- BEGIN: Page JS-->
  <!--script src="{{asset('public/esb_asset/js/scripts/pages/dashboard-ecommerce.min.js')}}"></script-->
  <script src="{{asset('public/esb_asset/js/app/my-script.js')}}"></script>
  <script src="{{ asset('public/plugins/toastr/toastr.min.js')}}"></script>
  <script src="{{asset('public/js/loadingoverlay.min.js')}}"></script>
  @php
        echo config('org_details.support_code');
 @endphp
  <!-- END: Page JS-->

  <script>
      $(window).on('load',  function(){
          if (feather) {
            feather.replace({ width: 14, height: 14 });
          }
       });
      function showOverlay(){
          let icon = $("#iconPath").val();
          let iconPath = icon+"/public/spinner.gif";
          $.LoadingOverlay("show", {
              image       : iconPath,
              //background: "rgba(0, 0, 0, 0.5)",
              //fontawesome : "fa fa-cog fa-spin fa-loading",
              fontawesomeAnimation: "1.5s fadein",
              fontawesomeAutoResize: true,
              //fontawesomeColor:"#ffcc00",
              fontawesomeResizeFactor:3,
          });
      }
      function hideOverlay(){
          $.LoadingOverlay("hide");
      }
  </script>
