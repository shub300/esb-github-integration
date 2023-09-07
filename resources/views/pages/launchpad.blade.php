@extends('layouts.master')

@section('head-content')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
   
    <style>
        .bold {
         
            font-weight: 400 !important;
        }

        .fa-2x {
            font-size: 1.5em !important;
        }

    </style>

@endsection
@php 
$parentUseCount = Auth::user()->parentUsers()->pluck('parent_id')->count();
$common_staff = $parentUseCount > 1 ? true : false;
@endphp
@section('title', $common_staff ? 'Choose Organization to Continue' : 'Launchpad')
@section('side-bar')
    @include('layouts.menu-bar')
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item active">Launchpad</li>
@endsection



@section('page-content')
    <input type="hidden" value="{{ url('/') }}" id="AjaxCallUrl">
    <input type="hidden" value="{{ env('CONTENT_SERVER_PATH') }}" id="contentServerPath">
    <div class="row ">


        <div class="col-md-12">
            <div class="card">
            <div class="card-body">
            <div class="dataTables_length">
                <form action="{{ route('launchpad') }}" method="get">

                    {{-- <label class="ftr-opt-lbl bold">Status
                    <select id="status"
                        class="custom-select custom-filter custom-select-sm form-control form-control-sm" name="status">
                        <option value="" {{Request::get('status')==""?'selected':''}}>-- Select Status --</option>
                        <option value="1" {{Request::get('status')=="1"?'selected':''}}>Active</option>
                        <option value="0" {{Request::get('status')=="0"?'selected':''}}>Inactive</option>
                       
                    </select>
                </label> --}}

                    <label class="ftr-opt-lbl bold">Search

                        <input type="search" name="search"
                            class="custom-select custom-filter custom-select-sm form-control form-control-sm"
                            placeholder="Search email,name etc" />
                    </label>
                    <label class="ftr-opt-lbl">
                        <div class="input-group">
                            <button type="submit" name="submit" class="btn btn-sm btn-primary">Search</button>&nbsp;
                            <a href="{{ url('launchpad') }}" name="reset" class="btn btn-sm btn-primary">Clear</a>

                        </div>
                    </label>
                </form>
                <br>
            </div>
            <div class="table-responsive">
                <table class="datatables-auditLogs table table-sm table-hover">
                    <thead>
                        <tr>

                            <th>Name</th>
                            <th>{{$common_staff ? 'Admin Email' : 'Email' }}</th>
                            @if(!$common_staff)
                            <th>Status</th>
                            @endif
                            <th>{{$common_staff ? 'Login' : 'Action' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($users->isNotEmpty())
                            @foreach ($users as $user)
                                <tr>

                                    <td>
                                        {{ $user->name }}
                                    </td>
                                    <td>
                                        {{ $user->email }}
                                    </td>
                                    @if(!$common_staff)
                                    <td>
                                        {!! $user->status == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>' !!}
                                    </td>
                                    @endif
                                    <td>
                                        <a href="{{ route('impersonate', ['id' => $user->id]) }}"
                                            class="btn btn-sm btn-icon">
                                            <i class="fa fa-sign-in fa-3x" data-toggle="tooltip"
                                                title="Click to login as user" data-placement="top" title="Actions"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td  colspan="4" class="text-center">
                                    No records found
                                </td>
                            </tr>

                        @endif
                    </tbody>
                </table>
                @if ($users->isNotEmpty())
              
                    {!! $users->appends(Request::except('page'))->render() !!}
                    <div>Showing {{ ($users->currentpage() - 1) * $users->perpage() + 1 }} to
                        {{ $users->currentpage() * $users->perpage() }}
                        of {{ $users->total() }} entries
                    </div>
                @endif

            </div>
            </div>
            </div>
        </div>

    </div>




    <!-- Workflow Section -->
@endsection
@push('page-script')
    <!-- select2 -->
    <script src="{{ asset('public/select2/js/script.js') }}"></script>
    <!--end -->
@endpush
