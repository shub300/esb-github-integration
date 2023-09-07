<!-- Basic Initialization -->
@php
use App\Http\Controllers\PanelControllers\ModuleAccessController;
$user_id = Auth::user()->id;
$user_role = Auth::user()->role;
$parentUserCount = Auth::user()->parentUsers()->pluck('parent_id')->count();

// if(Session::get('switch_to_staff_dashboard') && Session::get('switch_to_staff_role')){
//   $user_id = Session::get('switch_to_staff_dashboard');
//   $user_role = Session::get('switch_to_staff_role');
// }

@endphp
<!-- // Basic Initialization -->
<style>
  .main-menu .navbar-header{
        background-color:{{ isset($org_style->logo_header_bg_color) ? $org_style->logo_header_bg_color.' !important' : ''}};
    }
    .main-menu .navbar-header h2{
        color: {{ isset($org_style->logo_header_bg_color) ? '#fff !important' : ''}};
        height: {{ isset($org_style->logo_header_bg_color) ? '36px !important' : ''}};
        font-weight: {{ isset($org_style->logo_header_bg_color) ? '500 !important' : ''}};
    }
    .main-menu .navbar-header .collapse-toggle-icon{
        color: {{ isset($org_style->logo_header_bg_color) ? '#fff !important' : ''}};
    }
    .main-menu .navbar-header .toggle-icon{
        color: {{ isset($org_style->logo_header_bg_color) ? '#fff !important' : ''}};
    }
  .main-menu .main-menu-content {
    position: static !important;
    margin-top: 10px !important;
}
</style>
@if ($user_role == 'master_staff' || $parentUserCount>1)
    <div class="main-menu menu-fixed menu-light menu-accordion menu-shadow" data-scroll-to-active="true">
        <div class="navbar-header">
            <ul class="nav navbar-nav flex-row">
                <li class="nav-item mr-auto">
                    <a class="navbar-brand" href="{{ url('launchpad') }}">
                        <span class="brand-logo img-fluid">
                          
                          
                            @php
                                $logo_public_dir_path = config('org_details.logo');
                                $org_logo_url = env('CONTENT_SERVER_PATH') . $logo_public_dir_path;
                            @endphp
                          

                            @if (isset($logo_public_dir_path) && $logo_public_dir_path)
                                <img src="{{ $org_logo_url }}" alt="">
                            @else
                                <h2>{{ config('org_details.name') }}</h2>
                            @endif
                        </span>
                    </a>
                </li>
                <li class="nav-item nav-toggle"><a class="nav-link modern-nav-toggle pr-0" data-toggle="collapse"><i
                            class="d-block d-xl-none text-primary toggle-icon font-medium-4" data-feather="x"></i>
                            @if(!isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')]))
                            <i
                            class="d-none d-xl-block collapse-toggle-icon font-medium-4  text-primary"
                            data-feather="disc" data-ticon="disc"></i>
                            @endif

                          </a></li>
            </ul>
        </div>
        <div class="shadow-bottom"></div>
        <div class="main-menu-content">


            <ul class="navigation navigation-main" id="main-menu-navigation" data-menu="menu-navigation">
                @php
                    $view_integrations = ModuleAccessController::getAccessRight($user_id, $user_role, 'integrations', 'view');
                @endphp
                @if ($view_integrations == 1)
                    <li class="nav-item @if (Request::is('launchpad')) active @endif">
                        <a class="d-flex align-items-center" href="{{ url('/launchpad') }}"><i
                                data-feather="layers"></i><span class="menu-title text-truncate"
                                data-i18n="Integrations">Launchpad</span></a>
                    </li>
                @endif

            </ul>
        </div>
    </div>

    <!-- Main Sidebar Container -->
    {{-- <aside class="main-sidebar sidebar-dark-primary elevation-4">

  <a href="{{url('launchpad')}}" class="brand-link">
<span class="logo-lg" style="width: 100%;">
  @php
  $company_logo_url = env('ADMIN_BASE_PATH').config('org_details.logo');
  @endphp

  @if (isset($company_logo_url))
  <img src="{{ $company_logo_url }}" alt="" class="brand-image" style="float: none;width: 92%;height: 60px; max-height;max-height: none;">
  @else
  <h4>{{ config('org_details.name') }}</h4>
  @endif
</span>
</a>


<!-- Sidebar -->
<div class="sidebar">
  <!-- Sidebar Menu -->
  <nav class="mt-2">
    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
      <li class="nav-item">
        <a href="{{url('launchpad')}}" data-step="1" data-position='right' data-intro="App and activity information" class="nav-link @if (Request::is('launchpad')) active @endif">
          <i class="nav-icon fas fa-tachometer-alt"></i>
          <p>
            Home

          </p>
        </a>
      </li>




    </ul>
  </nav>
  <!-- /.sidebar-menu -->
</div>
<!-- /.sidebar -->
</aside> --}}
@else
    <div class="main-menu menu-fixed menu-light menu-accordion menu-shadow" data-scroll-to-active="true">
        <div class="navbar-header">
            <ul class="nav navbar-nav flex-row">
                <li class="nav-item mr-auto">
                    <a class="navbar-brand" href="{{ url('integrations') }}">
                        <span class="brand-logo img-fluid">
                          
                           
                            @php
                                $logo_public_dir_path = config('org_details.logo');
                                $org_logo_url = env('CONTENT_SERVER_PATH') . $logo_public_dir_path;
                            @endphp
                           

                            @if (isset($logo_public_dir_path) && $logo_public_dir_path)
                                <img src="{{ $org_logo_url }}" alt="">
                            @else
                                <h2>{{ config('org_details.name') }}</h2>
                            @endif
                        </span>
                    </a>
                </li>
                <li class="nav-item nav-toggle"><a class="nav-link modern-nav-toggle pr-0" data-toggle="collapse"><i
                            class="d-block d-xl-none text-primary toggle-icon font-medium-4" data-feather="x"></i>
                            @if(!isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')]))
                            <i
                            class="d-none d-xl-block collapse-toggle-icon font-medium-4  text-primary"
                            data-feather="disc" data-ticon="disc"></i>
                            @endif
                          </a></li>
            </ul>
        </div>
        <div class="shadow-bottom"></div>
        <div class="main-menu-content">


            <ul class="navigation navigation-main" id="main-menu-navigation" data-menu="menu-navigation">
                <!--<li class=" nav-item"><a class="d-flex align-items-center" href="index-2.html"><i data-feather="home"></i><span class="menu-title text-truncate" data-i18n="Dashboards">Dashboards</span><span class="badge badge-light-warning badge-pill ml-auto mr-1">2</span></a>
        <ul class="menu-content">
          <li><a class="d-flex align-items-center" href="dashboard-analytics.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Analytics">Analytics</span></a>
          </li>
          <li class="active"><a class="d-flex align-items-center" href="dashboard-ecommerce.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="eCommerce">eCommerce</span></a>
          </li>
        </ul>
      </li>-->
                {{-- <li class=" nav-item mt-2">
        <a class="d-flex align-items-center makeBtn waves-effect waves-float waves-light" href="{{url('/workflow')}}"><i data-feather="plus-circle"></i><span class="menu-title text-truncate" data-i18n="Email">re</span></a>
      </li> --}}

                <!-- <li class=" navigation-header"><span data-i18n="Apps &amp; Pages">Apps &amp; Pages</span><i data-feather="more-horizontal"></i>
      </li>  -->
                <!-- Users -->
                @php
                    $view_integrations = ModuleAccessController::getAccessRight($user_id, $user_role, 'integrations', 'view');
                @endphp
                @if ($view_integrations == 1)
                    <li class="nav-item @if (Request::is('integrations')) active @endif">
                        <a class="d-flex align-items-center" href="{{ url('/integrations') }}"><i
                                data-feather="layers"></i><span class="menu-title text-truncate"
                                data-i18n="Integrations">Integrations</span></a>
                    </li>
                @endif

                {{-- <li class="nav-item  @if (Request::is('workflow')) active @endif">
        <a class="d-flex align-items-center" href="{{url('/workflow')}}"><i data-feather="grid"></i><span class="menu-title text-truncate" data-i18n="My Apps">My Apps</span></a>
    </li> --}}

                @php
                    $view_logs = ModuleAccessController::getAccessRight($user_id, $user_role, 'logs', 'view');
                @endphp
                @if ($view_logs == 1 || $view_integrations == 1)
                    <li class="nav-item  @if (Request::is('myapps')) active @endif ">
                        <a class="d-flex align-items-center" href="{{ url('/myapps') }}"><i
                                data-feather="grid"></i><span class="menu-title text-truncate"
                                data-i18n="Active Integrations">Active
                                Integrations</span></a>
                    </li>
                @endif

                @php
                    $view_staff = ModuleAccessController::getAccessRight($user_id, $user_role, 'staff', 'view');
                @endphp
                @if ($view_staff == 1 && !isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')]))
                    <li class="nav-item  @if (Request::is('manage-staff')) active @endif ">
                        <a class="d-flex align-items-center" href="{{ url('/manage-staff') }}"><i
                                data-feather="users"></i><span class="menu-title text-truncate"
                                data-i18n="Manage Staff">Manage Staff</span></a>
                    </li>
                @endif

                <!--
      <li class=" nav-item"><a class="d-flex align-items-center" href="form-layout.html"><i data-feather="box"></i><span class="menu-title text-truncate" data-i18n="Form Layout">Form Layout</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="form-wizard.html"><i data-feather="package"></i><span class="menu-title text-truncate" data-i18n="Form Wizard">Form Wizard</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="form-validation.html"><i data-feather="check-circle"></i><span class="menu-title text-truncate" data-i18n="Form Validation">Form Validation</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="form-repeater.html"><i data-feather="rotate-cw"></i><span class="menu-title text-truncate" data-i18n="Form Repeater">Form Repeater</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="table-bootstrap.html"><i data-feather="server"></i><span class="menu-title text-truncate" data-i18n="Table">Table</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="#"><i data-feather="grid"></i><span class="menu-title text-truncate" data-i18n="Datatable">Datatable</span></a>
        <ul class="menu-content">
          <li><a class="d-flex align-items-center" href="table-datatable-basic.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Basic">Basic</span></a>
          </li>
          <li><a class="d-flex align-items-center" href="table-datatable-advanced.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Advanced">Advanced</span></a>
          </li>
        </ul>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="table-ag-grid.html"><i data-feather="grid"></i><span class="menu-title text-truncate" data-i18n="ag-grid">agGrid Table</span></a>
      </li>
      <li class=" navigation-header"><span data-i18n="Charts &amp; Maps">Charts &amp; Maps</span><i data-feather="more-horizontal"></i>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="#"><i data-feather="pie-chart"></i><span class="menu-title text-truncate" data-i18n="Charts">Charts</span><span class="badge badge-light-danger badge-pill ml-auto mr-2">2</span></a>
        <ul class="menu-content">
          <li><a class="d-flex align-items-center" href="chart-apex.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Apex">Apex</span></a>
          </li>
          <li><a class="d-flex align-items-center" href="chart-chartjs.html"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Chartjs">Chartjs</span></a>
          </li>
        </ul>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="maps-leaflet.html"><i data-feather="map"></i><span class="menu-title text-truncate" data-i18n="Leaflet Maps">Leaflet Maps</span></a>
      </li>
      <li class=" navigation-header"><span data-i18n="Misc">Misc</span><i data-feather="more-horizontal"></i>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="#"><i data-feather="menu"></i><span class="menu-title text-truncate" data-i18n="Menu Levels">Menu Levels</span></a>
        <ul class="menu-content">
          <li><a class="d-flex align-items-center" href="#"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Second Level">Second Level 2.1</span></a>
          </li>
          <li><a class="d-flex align-items-center" href="#"><i data-feather="circle"></i><span class="menu-item text-truncate" data-i18n="Second Level">Second Level 2.2</span></a>
            <ul class="menu-content">
              <li><a class="d-flex align-items-center" href="#"><span class="menu-item text-truncate" data-i18n="Third Level">Third Level 3.1</span></a>
              </li>
              <li><a class="d-flex align-items-center" href="#"><span class="menu-item text-truncate" data-i18n="Third Level">Third Level 3.2</span></a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="disabled nav-item"><a class="d-flex align-items-center" href="#"><i data-feather="eye-off"></i><span class="menu-title text-truncate" data-i18n="Disabled Menu">Disabled Menu</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="https://pixinvent.com/demo/vuexy-html-bootstrap-admin-template/documentation" target="_blank"><i data-feather="folder"></i><span class="menu-title text-truncate" data-i18n="Documentation">Documentation</span></a>
      </li>
      <li class=" nav-item"><a class="d-flex align-items-center" href="https://pixinvent.ticksy.com/" target="_blank"><i data-feather="life-buoy"></i><span class="menu-title text-truncate" data-i18n="Raise Support">Raise Support</span></a>
      </li>-->
            </ul>
        </div>
    </div>

   

@endif
