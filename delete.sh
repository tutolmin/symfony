#!/usr/bin/bash

types=(checkmate stalemate 1-0 0-1)
sides=(BlackSide WhiteSide)
depths=(fast deep)
statuses=(Pending Processing Skipped Partially Evaluated Complete)

random_side () {

  size=${#sides[*]}
  key=$((RANDOM % $size))

  side=${sides[$key]}
}

random_depth () {

  size=${#depths[*]}
  key=$((RANDOM % $size))

  depth=${depths[$key]}
}

random_type () {

  size=${#types[*]}
  key=$((RANDOM % $size))

  type=${types[$key]}
}

random_status () {

  size=${#statuses[*]}
  key=$((RANDOM % $size))

  status=${statuses[$key]}
}

queue_add () {

 random_type
 random_depth
 random_side

 php bin/console queue:add:random --side=$side --type=$type --depth=$depth
}

queue_promote () {

 php bin/console queue:promote:random
}

queue_delete () {

 php bin/console queue:delete:random
}

queue_fill () {

 random_type
 random_depth
 random_side

 number=$((15 + RANDOM % 10))

 php bin/console queue:fill --threshold=$number --side=$side --type=$type --depth=$depth
}

change_status () {

 if [[ $# -eq 0 ]]; then

  random_status

 else

  status=$1

 fi

 get_queue_length "$status"
 echo "Total $status nodes: $queue_length"

 php bin/console queue:change:random status $status
}

change_side () {

 random_side

 php bin/console queue:change:random side $side
}

change_type () {

 depth

 php bin/console queue:change:random type $depth
}

get_queue_length () {

 queue_length=`php bin/console queue:length --status=$1 | awk '{print $7}'`

}

# Forever cycle
while [[ true ]]; do

# Fill the queue with some items
#queue_fill

get_queue_length "Pending"
echo "Total Pending nodes: $queue_length"

#while [[ $queue_length -gt 15 ]]; do

while [[ true ]]; do

 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 queue_delete
 change_status "Evaluated"

 get_queue_length "Pending"
 echo
 echo "Total Pending nodes: $queue_length"
 echo

done

echo "Waiting for interruption"
sleep 6

done

<< 'MULTILINE-COMMENT'

php bin/console queue:change:random status Processing
php bin/console queue:change:random status Evaluated
php bin/console queue:change:random status Pending

MULTILINE-COMMENT
