
@if ($message = session('error'))
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <div class="alert-body">
        <small> {!! $message !!}</small>
    </div>
  </div>
@endif
@if ($message = session('warning'))
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <div class="alert-body">
        <small> {!! $message !!}</small>
    </div>
  </div>
@endif


@if ($message = session('success'))
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <div class="alert-body">
        <small> {!! $message !!}</small>
    </div>
  </div>
@endif



@if ($message = session('info'))
  <div class="alert alert-info alert-dismissible fade show" role="alert">
    <div class="alert-body">
        <small> {!! $message !!}</small>
    </div>
  </div>
@endif


@if ($errors->any())


    {{-- <div class="alert alert-danger mt-1 alert-validation-msg" role="alert">
      <div class="alert-body d-flex ">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-info me-50"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
        @foreach ($errors->all() as $error)
        <small>{{ $error }}</small><br>
        @endforeach
      </div>
    </div> --}}

@endif

@if ($message = Session::get('notice_success'))
<div class="alert alert-info alert-block">
	<button type="button" class="close" data-dismiss="alert">×</button>
	<i class="fa fa-exclamation-circle"></i> <span style="color:green">{{ $message }}</span>
</div>
@endif

@if ($message = Session::get('notice_warning'))
<div class="alert alert-info alert-block">
	<button type="button" class="close" data-dismiss="alert">×</button>
	<i class="fa fa-exclamation-circle"></i> <span style="color:red">{{ $message }}</span>
</div>
@endif
@if (Session::get('bp_oauth_error') && Session::get('bp_oauth_error_count')==1)

<div class="alert alert-danger alert-block">
	<button type="button" class="close" data-dismiss="alert">×</button>
    <i class="fas fa-times-circle"></i> <small>{{ Session::get('bp_oauth_error') }}</small>
</div>
@endif


