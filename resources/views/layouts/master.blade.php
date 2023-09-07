<!DOCTYPE html>
<html>
	<!-- begin::Head -->
	<head>
		@include('layouts.head-scripts')
        @include('layouts.inspectlet_asynchronous')
        @yield('head-content')
        @stack('page-style')
		@include('layouts.custom-style')
	</head>
	<!-- end::Head -->
	<!-- end::Body -->
	<body class="vertical-layout vertical-menu-modern  navbar-floating footer-static  " data-open="click" data-menu="vertical-menu-modern" data-url="{{ url('/')}}" data-content-path="{{env('CONTENT_SERVER_PATH')}}">
		<!-- begin:: Page -->

			@include('layouts.header')
			@yield('side-bar')
			<!-- begin::Body -->

			@yield('esb-flow-bar')

			<div class="app-content content ">
				<div class="content-overlay"></div>
				<div class="header-navbar-shadow"></div>
				<div class="content-wrapper">
					@include('layouts.page-content')
				</div>
			</div>
			<div class="sidenav-overlay"></div>
    		<div class="drag-target"></div>
			<!-- end:: Body -->
			@include('layouts.footer')

		@include('layouts.footer-script')
		@stack('page-script')
		<!--script for feather-icons -->
		{{-- <script src="https://unpkg.com/feather-icons"></script> --}}

	</body>
	<!-- end::Body -->
</html>
